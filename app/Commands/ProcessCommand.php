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

        // Load vernacular book names from the local YAML data (app/Data/by_language/{iso}.yaml)
        $lang_code = strtolower(substr($bible_id,0,3));
        $vernacular_books = $this->load_vernacular_books($lang_code);

        // Language details (e.g. autonym) previously came from the API. The local YAML
        // data does not carry them, so the v2 tagger falls back to the English name.
        $language_details = [];
        $vernacular_overrides = [];
        $use_english_for_all = false;
        foreach($chapters as $chapter_path) {
            if(!Str::contains($chapter_path,'.mp3')) {
                continue;
            }

            $current_book = $this->parse_values_from_path($bible_id, $chapter_path, $this->option('source_style'));

            // Check if vernacular name exists for this book ID
            if (!isset($vernacular_books[$current_book['id']])) {
                // Use cached override if we already asked about this book
                if (isset($vernacular_overrides[$current_book['id']])) {
                    $cached = $vernacular_overrides[$current_book['id']];
                    $current_book['id'] = $cached['id'];
                    $current_book['vname'] = $cached['vname'];
                    $current_book['has_vernacular'] = $cached['has_vernacular'];
                } elseif ($use_english_for_all) {
                    $current_book['vname'] = $current_book['book_name'];
                    $current_book['has_vernacular'] = false;
                } else {
                    $this->warn("No vernacular name found for book ID: " . $current_book['id']);
                    $this->info("File: " . basename($chapter_path));
                    $this->info("Current book mapping: " . $current_book['book_name'] . " (ID: " . $current_book['id'] . ")");
                    $this->info("Available vernacular books: " . implode(', ', array_keys($vernacular_books->toArray())));

                    $override = $this->ask("Enter a different book ID to use, 'all' for English fallback on all books, or press Enter to use English name '" . $current_book['book_name'] . "':");

                    $original_id = $current_book['id'];
                    if (strtolower($override) === 'all') {
                        $use_english_for_all = true;
                        $current_book['vname'] = $current_book['book_name'];
                        $current_book['has_vernacular'] = false;
                        $this->info("Using English names for all remaining books.");
                    } elseif (!empty($override)) {
                        $override = strtoupper($override);
                        if (isset($vernacular_books[$override])) {
                            $current_book['id'] = $override;
                            $current_book['vname'] = $vernacular_books[$override];
                            $current_book['has_vernacular'] = true;
                            $this->info("Using book ID: " . $override . " with vernacular name: " . $vernacular_books[$override]);
                        } else {
                            $this->error("Book ID '" . $override . "' not found in vernacular books. Using English name instead.");
                            $current_book['vname'] = $current_book['book_name'];
                            $current_book['has_vernacular'] = false;
                        }
                    } else {
                        $current_book['vname'] = $current_book['book_name'];
                        $current_book['has_vernacular'] = false;
                        $this->info("Using English name: " . $current_book['book_name']);
                    }

                    // Cache the result for subsequent chapters of this book
                    $vernacular_overrides[$original_id] = [
                        'id' => $current_book['id'],
                        'vname' => $current_book['vname'],
                        'has_vernacular' => $current_book['has_vernacular'],
                    ];
                }
            } else {
                $current_book['vname'] = $vernacular_books[$current_book['id']];
                $current_book['has_vernacular'] = true;
            }

            $current_book['folder_id'] = $folder_id;
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

    /**
     * Load vernacular book names from the local YAML data file for a language.
     *
     * Reads app/Data/by_language/{iso}.yaml and returns a collection keyed by
     * book id (e.g. "MAT") with the vernacular name as the value, matching the
     * shape the arc.dbs.org API previously returned.
     */
    private function load_vernacular_books($lang_code)
    {
        $yaml_file = base_path("app/Data/by_language/$lang_code.yaml");
        if (!file_exists($yaml_file)) {
            $this->error("No local book data found for language '$lang_code' at: $yaml_file");
            exit(1);
        }

        $books = [];
        $current_id = null;
        foreach (file($yaml_file, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^  "([^"]+)":\s*$/', $line, $matches)) {
                $current_id = $matches[1];
            } elseif ($current_id !== null && preg_match('/^    name:\s*"(.*)"\s*$/u', $line, $matches)) {
                $books[$current_id] = $matches[1];
            }
        }

        return collect($books);
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
            case "fcbh2":
                // Format: {iso}{orgcode}{TestamentLetter}{Drama/nondrama}_B{BookNumericID}_{BookID}_{ThreeLetterPaddedChapter}
                // Example: FVRWBTN2DA_B01_MAT_001.mp3
                $book_id = strtoupper($book_parts[2]);
                $current_book = $book_index[$book_id];
                $chapter_number = $book_parts[3];
            break;
            case "dbl":
                $current_book = $book_index[$book_parts[0]];
                $chapter_number = $book_parts[1];
            break;
            case "dbs":
                $chapter_number = $book_parts[2];
                $book_number = (int)$book_parts[0];
                $book_name_from_file = $book_parts[1];

                // Check if this might be a New Testament book with flexible numbering
                // Matthew can be 40 or 41, and subsequent NT books follow
                $nt_offset = 0;
                if ($book_number == 40) {
                    // Using 40-based numbering for NT (Matthew = 40)
                    $nt_offset = -1;
                }

                // First try to match by BOTH number AND name to avoid conflicts
                foreach($book_index as $book) {
                    $order_num = $book['order_' . $this->option('sort_type')];
                    $matches_name = (str_replace(' ', '', $book['name']) == $book_name_from_file);

                    // Check if both number and name match
                    if ($matches_name) {
                        // For NT books with offset
                        if ($book['book_testament'] == 'NT' && $nt_offset != 0 && $order_num + $nt_offset == $book_number) {
                            $current_book = $book;
                            break;
                        }
                        // Standard check
                        if ($order_num == $book_number) {
                            $current_book = $book;
                            break;
                        }
                    }
                }

                // If not found by number+name combo, try by number only (prioritizing OT/NT canonical books)
                if(!isset($current_book)) {
                    foreach($book_index as $book) {
                        $order_num = $book['order_' . $this->option('sort_type')];

                        // Skip apocryphal books if we have a name hint
                        if ($book['book_testament'] == 'AP' && !empty($book_name_from_file)) {
                            continue;
                        }

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
                }

                // If still not found, try by name only (with spaces removed for comparison)
                if(!isset($current_book)) {
                    foreach($book_index as $book) {
                        if(str_replace(' ', '', $book['name']) == $book_name_from_file) {
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
