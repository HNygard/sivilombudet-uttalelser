<?php
/**
 * This script downloads all uttalelser from Sivilombudsmannen
 *
 * Based on code from Norske-postlister.no
 *
 * @author Hallvard Nygård, @hallny
 */

set_error_handler(function ($errno, $errstr, $errfile, $errline, array $errcontext) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\DomCrawler\Crawler;

$cacheTimeSeconds = 60 * 60 * 24 * 4;
$cache_location = __DIR__ . '/cache';
$baseUrl = 'https://www.sivilombudsmannen.no/uttalelser/';
$updateDate = date('H:i:s d.m.Y');

mkdirIfNotExists($cache_location);

$mainPage = getUrlCachedUsingCurl($cacheTimeSeconds, $cache_location . '/page-1.html', $baseUrl);
$items = readItems($mainPage);

$obj = new stdClass();
$lastPage = (int)end($items['pages']);
$obj->pageCount = $lastPage;
$obj->itemCount = 0;
$obj->lastUpdated = $updateDate;
$obj->items = $items['items'];

for ($pageNum = 2; $pageNum <= $lastPage; $pageNum++) {
	$pageHtml = getUrlCachedUsingCurl($cacheTimeSeconds, $cache_location . '/page-' . $pageNum . '.html', $baseUrl . 'page/' . $pageNum . '/');
	$items2 = readItems($pageHtml);
	var_dump($items2);
	$obj->items = array_merge($obj->items, $items['items']);
}

$obj->itemCount = count($obj->items);

file_put_contents(__DIR__ . '/uttalelser.json', json_encode($obj, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE));


function htmlHeading($title = 'Sivilombudsmannens uttalelser') {
    return "<!DOCTYPE html>
<html>
<head>
  <meta charset=\"UTF-8\">
  <title>$title</title>
</head>
<body>
<style>
table th {
text-align: left;
max-width: 300px;
border: 1px solid lightgrey;
padding: 2px;
}
table td {
text-align: right;
border: 1px solid lightgrey;
padding: 2px;

}
table {
border-collapse: collapse;
}
</style>";
}

$html = htmlHeading() . "

<h1>Sivilombudsmannens uttalelser</h1>\n";
$html .= "Laget av <a href='https://twitter.com/hallny'>@hallny</a> / <a href='https://norske-postlister.no'>Norske-postlister.no</a><br>\n";
$html .= "<a href='https://github.com/HNygard/sivilombudsmannen-uttalelser/'>Kildekode for oppdatering av denne lista</a> (Github)<br><br>\n\n";
$html .= '
<ul>
	<li>Antall uttalelser: ' . $obj->itemCount . '</li>
	<li>Liste sist oppdatert: ' . $updateDate . '</li>
	<li>Kilde: <a href="' . $baseUrl . '">' . $baseUrl . '</a></li>
</ul>

<table>
';
foreach ($obj->items as $item) {
$html .= '
	<tr>
		<th>'. $item['datoUttalelse'] . ' <span style="font-weight: normal;">(' . $item['datoPublisert'] . ')</span></th>
		<td>' . $item['tittel'] . '</td>
	</tr>
';
}

$html .= '
</table>
';

file_put_contents(__DIR__ . '/docs/index.html', $html);


function readItems($html) {
	$crawler = new Crawler($html);
	return array(
		'items' => $crawler->filter('main section article.list--results.list-item')->each(function (Crawler $node, $i) {

			$footer_text = $node->filter('.list-item__footer span')->each(function (Crawler $node, $i) {
			    return $node->text('', true);
			});
			$datoUttalelse = null;
			$datoPublisert = null;
			$sivilombudsmannenSaksnummer = null;
			foreach ($footer_text as $text) {
				if (str_starts_with($text, 'Dato for uttalelse: ')) {
					$datoUttalelse = explode('.', trim(substr($text, strlen('Dato for uttalese: '))));
					$datoUttalelse = mktime(0, 0, 0, $datoUttalelse[1], $datoUttalelse[0], $datoUttalelse[2]);
					$datoUttalelse = date('d.m.Y', $datoUttalelse);
				}
				elseif (str_starts_with($text, 'Saksnummer: ')) {
					$sivilombudsmannenSaksnummer = trim(substr($text, strlen('Saksnummer: ')));
				}
				elseif (str_starts_with($text, 'Publisert: ')) {
					$datoPublisert = explode('.', trim(substr($text, strlen('Publisert: '))));
					$datoPublisert = mktime(0, 0, 0, $datoPublisert[1], $datoPublisert[0], $datoPublisert[2]);
					$datoPublisert = date('d.m.Y', $datoPublisert);
				}
				else {
					var_dump($footer_text);
					throw new Exception('Unknown: ' . $text);
				}
			}

			return array(
				'datoUttalelse' => $datoUttalelse,
				'datoPublisert' => $datoPublisert,
				'sivilombudsmannenSaksnummer' => $sivilombudsmannenSaksnummer,
				'url' => $node->filter('a')->first()->attr('href'),
				'tittel' => $node->filter('h1')->first()->text('', true),
				'beskrivelse' => $node->filter('.list-item__desc')->first()->text('', true)
			);
		}),
		'pages' => $crawler->filter('.pagination__pages li')->each(function (Crawler $node, $i) {
			return $node->text('', true);
		})
	);
}

function getUrlCachedUsingCurl($cacheTimeSeconds, $cache_file, $baseUri, $acceptContentType = '') {
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cacheTimeSeconds) {
        return file_get_contents($cache_file);
    }
    logInfo('   - GET ' . $baseUri);
    $ci = curl_init();
    curl_setopt($ci, CURLOPT_URL, $baseUri);
    curl_setopt($ci, CURLOPT_TIMEOUT, 200);
    curl_setopt($ci, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ci, CURLOPT_FORBID_REUSE, 0);
    curl_setopt($ci, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ci, CURLOPT_HEADER, 1);
    $headers = array(
    );
    if ($acceptContentType != '') {
        $headers[] = 'Accept: ' . $acceptContentType;
    }
    curl_setopt($ci, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ci);
    if ($response === false) {
        throw new Exception(curl_error($ci), curl_errno($ci));
    }

    $header_size = curl_getinfo($ci, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $header_size);
    $body = substr($response, $header_size);
    curl_close($ci);

    logInfo('   Response size: ' . strlen($body));

    if (!str_starts_with($header, 'HTTP/1.1 200 OK')) {
        if (str_starts_with($header, 'HTTP/1.1 404 Not Found') && file_exists($cache_file)) {
            logInfo('  -> 404 Not Found. Using cache.');
            return file_get_contents($cache_file);
        }
        logInfo('--------------------------------------------------------------' . chr(10)
            . $body . chr(10) . chr(10)
            . '--------------------------------------------------------------' . chr(10)
            . $header . chr(10) . chr(10)
            . '--------------------------------------------------------------');
        throw new Exception('Server did not respond with 200 OK.' . chr(10)
            . 'URL ...... : ' . $baseUri . chr(10)
            . 'Status ... : ' . explode(chr(10), $header)[0]
        );
    }

    if (trim($body) == '') {
        throw new Exception('Empty response.');
    }
    file_put_contents($cache_file, $body);
    return $body;
}

function str_starts_with($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) == $needle;
}

function str_ends_with($haystack, $needle) {
    $length = strlen($needle);
    return $length === 0 || substr($haystack, -$length) === $needle;
}

function str_contains($stack, $needle) {
    return (strpos($stack, $needle) !== FALSE);
}

function logDebug($string) {
    //logLine($string, 'DEBUG');
}

function logInfo($string) {
    logLine($string, 'INFO');
}

function logError($string) {
    logLine($string, 'ERROR');
}

function logLine($string, $log_level) {
    global $run_key;
    echo date('Y-m-d H:i:s') . ' ' . $log_level . ' --- ' . $string . chr(10);

    if (isset($run_key) && !empty($run_key)) {
        // -> Download runner
        global $entity, $argv, $download_logs_directory;
        global $last_method;
        $line = new stdClass();
        $line->timestamp = time();
        $line->level = $log_level;
        $line->downloader = $argv[2];
        if (isset($entity) && isset($entity->entityId)) {
            $line->entity_id = $entity->entityId;
        }
        $line->last_method = $last_method;
        $line->message = $string;
        // Disabled.
        //file_put_contents($download_logs_directory . '/' . $run_key . '.json', json_encode($line) . chr(10), FILE_APPEND);
    }
}


function mkdirIfNotExists($dir) {
    if(!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

