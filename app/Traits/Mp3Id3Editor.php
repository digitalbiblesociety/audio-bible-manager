<?php

namespace App\Traits;

use App\Traits\Languages;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

trait Mp3Id3Editor {

    use Languages;

    public function tag_mp3($bible_id, $input_path, $output_path, $ref, $player_type = 'pearl')
    {
        switch($player_type) {
            case "pearl":
                $this->tag_pearl_player($input_path, $output_path, $ref);
                break;
            case "pearl_v2":
                $this->tag_pearl_player_v2($input_path, $output_path, $ref);
                break;
            case "bible-library":
                $this->tag_bible_library($input_path, $output_path, $ref);
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

        $process = new Process([
            "ffmpeg",
            '-i',
            $input_path,
            '-c',
            'copy',
            "-metadata",
            "title=$title",
            "-metadata",
            "artist=$language",
            "-metadata",
            "album=$book",
            "-metadata",
            "genre=$genre",
            $output_path
        ]);
        //dd($process->getCommandLine());
        $process->setTimeout(12000);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
            $this->error("Failed to Process the file $input_path");
            dd("I'm sorry");
        }

        // exec("ffmpeg -i \"$input_path\" -c copy -metadata title=\"$title\" -metadata artist=\"$language\" -metadata album=\"$book\" -metadata genre=\"$genre\" \"$output_path\"");
    }

    private function tag_pearl_player_v2($input_path, $output_path, $ref) {
        $title    = mb_convert_encoding($ref['book_name'] .' '.ltrim($ref['chapter_number'],'0'), 'UTF-8', 'ISO-8859-1');
        $iso      = mb_convert_encoding(strtolower(substr($ref['bible_id'],0,3)), 'UTF-8', 'ISO-8859-1');
        $language = mb_convert_encoding($this->languages(strtolower(substr($ref['bible_id'],0,3))), 'UTF-8', 'ISO-8859-1');
        $book     = $ref['vname'] ?? $ref['book_name'];
        $genre    = mb_convert_encoding($ref['testament'].'-'.substr($ref['bible_id'],3), 'UTF-8', 'ISO-8859-1');
        $font     = $this->option('font');


        // 
        $output_folder = substr($output_path, 0, (int) strrpos($output_path, '/'));
        if(!Storage::exists($output_folder)) {
            Storage::makeDirectory($output_folder);
        }

        $process = new Process([
            "ffmpeg",
            '-i',
            $input_path,
            '-c',
            'copy',
            "-metadata",
            "TLAN=$iso",
            "-metadata",
            "TIT3=$output_folder",
            "-metadata",
            "TIT1=$language",
            "-metadata",
            "TPE3=$language",
            "-metadata",
            "TCON=$genre",
            "-metadata",
            "TALB=$book",
            "-metadata",
            "TIT2=$title",
            "-metadata",
            "TENC=$font",
            $output_path
        ]);

        $process->setTimeout(12000);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
            $this->error("Failed to Process the file $input_path");
        }

        // exec("ffmpeg -i \"$input_path\" -c copy -metadata title=\"$title\" -metadata artist=\"$language\" -metadata album=\"$book\" -metadata genre=\"$genre\" \"$output_path\"");
    }

}