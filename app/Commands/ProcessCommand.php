<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Traits\Mp3Id3Editor;
use App\Traits\Books;

class ProcessCommand extends Command
{
    use Books;
    use Mp3Id3Editor;
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'process {bible_id} {destination=pearl} {--sort_type=protestant} {--source_style=dbl} {--tagid3=all}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Convert Audio Bible mp3s to various formats';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->structure_books();
    }

    private function structure_books()
    {
        switch($this->argument('destination')) {
            case "human":
                $this->structure_books_for_humans();
            break;
            case "megavoice":
                $this->structure_books_for_megavoice();
                break;
            case "pearl":
                $this->structure_books_for_pearl_player();
                break;
        }
        $this->line("All Done");
    }

    private function structure_books_for_pearl_player()
    {        
        $bible_id = $this->argument('bible_id');
        
        $chapters = Storage::disk('local')->files("bibles/source/$bible_id");
        foreach($chapters as $chapter_path) {
            if(!Str::contains($chapter_path,'.mp3')) {
                continue;
            }

            $current_book = $this->parse_values_from_path($chapter_path, $this->option('source_style'));
            $output_path = 'bibles/output/'.$bible_id.'/'.Str::slug($current_book['testament']).'/'.$current_book['book_number'].'_'.$current_book['book_name'].'/'.$current_book['book_number'].'_'.$current_book['book_name'].'_'.$current_book['chapter_number'].'.mp3';

            if($this->option('tagid3') != 'all') {
                Storage::disk('local')->move(
                    $chapter_path,
                    $output_path
                );
            } else {
                $this->tag_mp3($chapter_path, $output_path, $current_book, 'pearl');
            }
            
        }
    }

    private function structure_books_for_megavoice()
    {
        $this->line("Processing books for Megavoice");
        $books = $this->books($this->option('sort_type'));
        foreach($books as $book) {
            
        }
    }

    private function structure_books_for_humans()
    {

    }

    private function parse_values_from_path($chapter_path, $source_style = 'dbl')
    {
        $book_index = $this->books($this->option('sort_type'));
        $book_parts = explode('_', basename($chapter_path,'.mp3'));
        switch($source_style) {
            case "dbl":
                $current_book = $book_index[$book_parts[0]];
                $chapter_number = $book_parts[1];
            break;
            case "dbs":
                $chapter_number = $book_parts[2];
                foreach($book_index as $book) {
                    if($book['name'] == $book_parts[1]) {
                        $current_book = $book;
                    }
                }
                if(!isset($current_book)) {
                    $this->error("Could not find book");
                    dd($book_parts);
                }
            break;
        }
        return [
            'bible_id'       => $this->argument('bible_id'),
            'book_number'    => Str::padLeft($current_book['order_'.$this->option('sort_type')], 2, '0'),
            'id'             => $current_book['id'],
            'chapter_number' => $chapter_number,
            'testament'      => $current_book['book_testament'],
            'book_name'      => str_replace(' ', '', $current_book['name'])
        ];

    }

}
