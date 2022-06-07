<?php

namespace App\Traits;

use App\Traits\Languages;
use Illuminate\Support\Facades\Storage;

trait Mp3Id3Editor {

    use Languages;

    public function tag_mp3($input_path, $output_path, $ref, $player_type = 'pearl')
    {
        switch($player_type) {
            case "pearl":
                $this->tag_pearl_player($input_path, $output_path, $ref);
                break;
            case "megavoice":
                break;
        }
    }

    private function tag_pearl_player($input_path, $output_path, $ref) {
        $title    = mb_convert_encoding($ref['book_name'] .' '.ltrim($ref['chapter_number'],'0'), 'UTF-8', 'ISO-8859-1');
        $language = mb_convert_encoding($this->languages(strtolower(substr($ref['bible_id'],0,3))), 'UTF-8', 'ISO-8859-1');
        $book     = mb_convert_encoding($ref['book_name'], 'UTF-8', 'ISO-8859-1');
        $genre    = mb_convert_encoding($ref['testament'].'-'.substr($ref['bible_id'],3), 'UTF-8', 'ISO-8859-1');

        // 
        $output_folder = substr($output_path, 0, (int) strrpos($output_path, '/'));
        if(!Storage::exists($output_folder)) {
            Storage::makeDirectory($output_folder);
        }

        exec("ffmpeg -i $input_path -c copy -metadata title=\"$title\" -metadata artist=\"$language\" -metadata album=\"$book\" -metadata genre=\"$genre\" $output_path");
    }

}