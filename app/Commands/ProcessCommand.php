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
    protected $signature = 'process {bible_id} {destination=pearl} {--sort_type=protestant} {--source_style=dbl} {--tagid3=all} {--font=terminus.ttf}';

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
            case "pearl_v2":
                $this->structure_books_for_pearl_player();
                break;
        }
        $this->line("All Done");
    }

    private function structure_books_for_pearl_player()
    {        
        $folder_id = $this->argument('bible_id');
        $bible_id = explode('_', $folder_id);
        $bible_id = $bible_id[0];
        
        $chapters = Storage::disk('local')->files("bibles/source/$folder_id");

        $context = stream_context_create([
            'http' => [
                'header' => "User-Agent: Audio-Bible-Manager/1.0 (https://github.com/digital-bible-society/audio-bible-manager)\r\n"
            ]
        ]);

        // Try to fetch vernacular books from API, fallback to local JSON if it fails
        $lang_code = strtolower(substr($bible_id,0,3));
        $books_json = @file_get_contents("https://arc.dbs.org/api/bible-books/".$lang_code, false, $context);
        if ($books_json === false) {
            $local_books_file = base_path("app/Data/bible-books-".$lang_code.".json");
            if (file_exists($local_books_file)) {
                $books_json = file_get_contents($local_books_file);
                $this->warn("Using local fallback for bible-books: ".$local_books_file);
            } else {
                $this->error("Failed to fetch bible-books from API and no local fallback found at: ".$local_books_file);
                exit(1);
            }
        }
        $vernacular_books = collect(json_decode($books_json))->pluck('name','book_id');

        // Try to fetch language details from API, fallback to local JSON if it fails
        $language_json = @file_get_contents("https://arc.dbs.org/api/languages/".$lang_code, false, $context);
        if ($language_json === false) {
            $local_lang_file = base_path("app/Data/languages-".$lang_code.".json");
            if (file_exists($local_lang_file)) {
                $language_json = file_get_contents($local_lang_file);
                $this->warn("Using local fallback for language: ".$local_lang_file);
            } else {
                $this->error("Failed to fetch language from API and no local fallback found at: ".$local_lang_file);
                exit(1);
            }
        }
        $language_details = collect(json_decode($language_json))->toArray();
        foreach($chapters as $chapter_path) {
            if(!Str::contains($chapter_path,'.mp3')) {
                continue;
            }

            $current_book = $this->parse_values_from_path($bible_id, $chapter_path, $this->option('source_style'));
            $current_book['vname'] = $vernacular_books[$current_book['id']];
            $current_book['language_details'] = $language_details;
            $output_path = 'bibles/output/'.$folder_id.'/'.strtoupper(Str::slug($current_book['testament'])).'_'.$bible_id.'/'.$current_book['book_number'].'_'.$current_book['book_name'].'/'.$current_book['book_number'].'_'.$current_book['book_name'].'_'.$current_book['chapter_number'].'.mp3';

            if($this->option('tagid3') != 'all') {
                Storage::disk('local')->move(
                    $chapter_path,
                    $output_path
                );
            } else {
                $this->tag_mp3($bible_id, $chapter_path, $output_path, $current_book, $this->argument('destination'));
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

    private function parse_values_from_path($bible_id, $chapter_path, $source_style = 'dbl')
    {
        $chapter_name = preg_replace('/_+/m', '_', basename($chapter_path,'.mp3'));

        $book_index = $this->books($this->option('sort_type'));
        $book_parts = explode('_', $chapter_name);
        switch($source_style) {
            case "fcbh":
                $title = $chapter_name;
                foreach($book_index as $book) {
                        if($book['book_testament'] == (substr($book_parts[0], 0,1) == "A" ? "OT" : "NT") && $book['order_testament'] == substr($book_parts[0], 1)) {
                            $current_book = $book;
                        }
                }
                $chapter_number = str_pad($book_parts[1],3,'0', STR_PAD_LEFT);

            break;
            case "dbl":
                $current_book = $book_index[$book_parts[0]];
                $chapter_number = $book_parts[1];
            break;
            case "dbs":
                $chapter_number = $book_parts[2];
                $book_number = (int)$book_parts[0];

                // Check if this might be a New Testament book with flexible numbering
                // Matthew can be 40 or 41, and subsequent NT books follow
                $nt_offset = 0;
                if ($book_number == 40) {
                    // Using 40-based numbering for NT (Matthew = 40)
                    $nt_offset = -1;
                }

                // Try to find book by order in the overall Bible
                foreach($book_index as $book) {
                    $order_num = $book['order_' . $this->option('sort_type')];

                    // For NT books, check with possible offset
                    if ($book['book_testament'] == 'NT' && $nt_offset != 0) {
                        if ($order_num + $nt_offset == $book_number) {
                            $current_book = $book;
                            break;
                        }
                    }

                    // Standard check without offset
                    if ($order_num == $book_number) {
                        $current_book = $book;
                        break;
                    }

                    // Also check order_testament for OT books
                    if ($book['book_testament'] == 'OT' && $book['order_testament'] == $book_number) {
                        $current_book = $book;
                        break;
                    }
                }

                // If not found by number, try by name (with spaces removed for comparison)
                if(!isset($current_book)) {
                    foreach($book_index as $book) {
                        if(str_replace(' ', '', $book['name']) == $book_parts[1]) {
                            $current_book = $book;
                            break;
                        }
                    }
                }

                if(!isset($current_book)) {
                    $this->error("Could not find book with number: ".$book_parts[0]." or name: ".$book_parts[1]);
                    dd($book_parts);
                }
            break;
        }
        return [
            'bible_id'       => $bible_id,
            'book_number'    => Str::padLeft($current_book['order_'.$this->option('sort_type')], 2, '0'),
            'id'             => $current_book['id'],
            'chapter_number' => $chapter_number,
            'testament'      => $current_book['book_testament'],
            'book_name'      => str_replace(' ', '', $current_book['name'])
        ];
    }

}
