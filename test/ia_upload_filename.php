<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Service/MediaLocalPath.php';

$media = new class {
    public function hasOriginal(): bool
    {
        return true;
    }

    public function filename(): string
    {
        return '388443341143a4108272328a5cc933256ffe0417.png';
    }

    public function source(): string
    {
        return 'internet-archive-logo-wordmark.png';
    }
};

$path = new InternetArchiveOutboundSync\Service\MediaLocalPath();
$name = $path->iaUploadFilename($media);
if ($name !== 'internet-archive-logo-wordmark.png') {
    echo 'FAIL expected internet-archive-logo-wordmark.png got ' . json_encode($name) . "\n";
    exit(1);
}

echo "OK iaUploadFilename uses o:source\n";
