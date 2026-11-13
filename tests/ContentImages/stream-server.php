<?php

declare(strict_types=1);

// router of the PHP built-in server StreamDownloaderTest downloads from
switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
	case '/image.png':
		header('Content-Type: image/png');
		echo "\x89PNG\r\n\x1a\nimage";
		break;

	case '/redirect':
		header('Location: /image.png', true, 302);
		break;

	case '/loop':
		header('Location: /loop', true, 302);
		break;

	case '/host':
		echo $_SERVER['HTTP_HOST'];
		break;

	case '/big':
		echo str_repeat('x', 2048);
		break;

	default:
		http_response_code(404);
		echo 'not found';
}
