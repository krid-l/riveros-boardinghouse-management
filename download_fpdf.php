<?php
copy('http://www.fpdf.org/en/dl.php?v=186&f=zip', 'fpdf.zip');
$zip = new ZipArchive;
if ($zip->open('fpdf.zip') === TRUE) {
    $zip->extractTo('includes/fpdf');
    $zip->close();
    unlink('fpdf.zip');
    echo "FPDF successfully installed.\n";
} else {
    echo "Failed to extract FPDF.\n";
}
