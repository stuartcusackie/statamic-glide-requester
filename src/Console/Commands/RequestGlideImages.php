<?php

namespace stuartcusackie\StatamicGlideRequester\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Entry;
use Illuminate\Support\Facades\Http;
use simplehtmldom\HtmlDocument;
use stuartcusackie\StatamicGlideRequester\Jobs\VisitGlideUrl;
use Illuminate\Support\Str;

class RequestGlideImages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'glide:request';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Visits every routable Statamic entry and adds each glide image url to a queue for retrieval.';

    /**
     * The total images queued
     */
    protected $images = 0;
    
    /**
     * The source attributes
     * to search for
     */
    protected $sourceAttributes = [
        'srcset',
        'lazy-srcset'
    ];

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->call('queue:clear', ['connection' => 'redis',  '--queue' => 'gliderequester']);

        $htmlClient = new HtmlDocument();
        $entries = Entry::all();

        $this->info('Checking for glide images in all routable entries. This could take quite a while...');

        foreach($entries as $entry) {

            if($entry->url) {
                
                $response = Http::get(url($entry->url));

                if($response->failed()) {
                    $this->info('An entry could not be retrieved: ' . url($entry->url));
                    continue;
                }

                $htmlClient->load($response->body());

                foreach($htmlClient->find('picture') as $pictureEl) {

                    // Handle the image
                    $imgEl = $pictureEl->find('img', 0);
                    $this->addImageJob($imgEl->src);

                    // Handle the sources
                    foreach($pictureEl->find('source') as $sourceEl) {
                        
                        foreach($this->$sourceAttributes as $attr) {
                            
                            if($sourceEl->hasAttribute($attr)) {

                                foreach(explode(', ', $sourceEl->getAttribute($attr)) as $path) {
                                    $this->addImageJob($path);
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->info($this->images . ' glide images queued for retrieval. You can now run the gliderequester queue on redis.');

        return 0;
    }

    protected function addImageJob($path) {

        // Remove any source dimensions
        $path = explode(' ', $path)[0];

        if(Str::startsWith($path, '/img/')) {
            $this->info('Adding image job: ' . url($path));
            VisitGlideUrl::dispatch(url($path));
            $this->images++;
        }

    }
}