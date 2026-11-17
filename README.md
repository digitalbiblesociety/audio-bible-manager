# Audio-Bible-Manager

Processes Mp3 files for Pearl Players, Mega Voices, and other audio players.


## Process

The process command moves or copies mp3s from the bibles/source/{BIBLE_ID} folder to the bibles/output/ folder using the structure required for the player.

> process ENGKJV pearl --sort_type=protestant --source_style=dbl --tagid3=all

- source_style: one of __dbs, dbl, fcbh__

The format that the process command expects the source audio Bible mp3s to be in.

`fcbh` reads the book from the USFM 3.0 book ID when the file name has one (`RHGWFWN1DA_B25_3JN_001.mp3`), and otherwise from the FCBH book number (`B01___01_Matthew_____ENGESVN2DA.mp3`). `fcbh2` is kept as an alias of `fcbh`.

- destination: one of __human, megavoice, pearl__

The output format that the command should convert to

- tagid3: one of __all, none__

Determines if the id3 tags are altered
