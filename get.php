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
	$obj->items = array_merge($obj->items, $items2['items']);
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
	white-space: nowrap;
}
table td {
	text-align: left;
	border: 1px solid lightgrey;
	padding: 2px;
	white-space: nowrap;
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
	<thead>
		<tr>
			<th>Dato - uttalelse (publisert)</th>
			<th>Saksnummer</th>
			<th>Uttalelse</th>
			<th>Referanser til lov</th>
			<th>Tittel</th>
		</tr>
	</thead>
';
foreach ($obj->items as $item) {
	$urls = array();
	if ($item['url-norske-postlister.no'] != null) {
		foreach ($item['url-norske-postlister.no'] as $caseNum => $url) {
			$urls[] = '<a href="'.$url.'"><img src="https://norske-postlister.no/favicon-16x16.png"> ' . $caseNum . '</a>';
		}
	}
	$lovReferanser = array();
	if (count($item['tittel_lovRef']) > 0) {
		$lovReferanser[] = '<b>Lov ref. i tittel:</b><br>' . chr(10) . implode(',<br>' . chr(10), $item['tittel_lovRef']);
	}
	if (count($item['beskrivelse_lovRef']) > 0) {
		$lovReferanser[] = '<b>Lov ref. i beskrivelse:</b><br>' . chr(10) . implode(',<br>' . chr(10), $item['beskrivelse_lovRef']);
	}

	$html .= '
	<tr>
		<th>' . $item['datoUttalelse'] . ' <span style="font-weight: normal;">(' . $item['datoPublisert'] . ')</span></th>
		<td>' . implode(',<br>' . chr(10), $urls) . '</td>
		<td>[<a href="' . $item['url'] . '">Til uttalelse</a>]</td>
		<td>' . implode("<br><br>\n", $lovReferanser) . '</td>
		<td>' . $item['tittel'] . '</td>
	</tr>
';
}

$html .= '
</table>
';

file_put_contents(__DIR__ . '/index.html', $html);


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
			$npUrl = null;
			foreach ($footer_text as $text) {
				if (str_starts_with($text, 'Dato for uttalelse: ')) {
					$datoUttalelse = explode('.', trim(substr($text, strlen('Dato for uttalese: '))));
					$datoUttalelse = mktime(0, 0, 0, $datoUttalelse[1], $datoUttalelse[0], $datoUttalelse[2]);
					$datoUttalelse = date('d.m.Y', $datoUttalelse);
				}
				elseif (str_starts_with($text, 'Saksnummer: ')) {
					$text = str_replace('Saksnummer: 12/', 'Saksnummer: 2012/', $text);
					$text = str_replace('Saksnummer: 209/2897', 'Saksnummer: 2009/2897', $text);
					$text = str_replace('Saksnummer: 9/', 'Saksnummer: 2009/', $text);
					$text = str_replace('Saksnummer: 8/', 'Saksnummer: 2008/', $text);
					$text = str_replace('Saksnummer: 7/', 'Saksnummer: 2007/', $text);
					$sivilombudsmannenSaksnummer = trim(substr($text, strlen('Saksnummer: ')));

					// Clean up content
					$sivilombudsmannenSaksnummer2 = str_replace('sak ', '', $sivilombudsmannenSaksnummer);
					$sivilombudsmannenSaksnummer2 = str_replace('Sak ', '', $sivilombudsmannenSaksnummer2);
					$sivilombudsmannenSaksnummer2 = str_replace('tidl.', '', $sivilombudsmannenSaksnummer2);
					$sivilombudsmannenSaksnummer2 = str_replace('tidligere ', '', $sivilombudsmannenSaksnummer2);
					$sivilombudsmannenSaksnummer2 = str_replace(' og ', ' ', $sivilombudsmannenSaksnummer2);
					$sivilombudsmannenSaksnummer2 = str_replace(', ', ' ', $sivilombudsmannenSaksnummer2);
					$sivilombudsmannenSaksnummer2 = str_replace('(', '', $sivilombudsmannenSaksnummer2);
					$sivilombudsmannenSaksnummer2 = str_replace(')', '', $sivilombudsmannenSaksnummer2);
					$npUrl = array();
					foreach (explode(' ', $sivilombudsmannenSaksnummer2) as $caseNum) {
						$caseNum = trim($caseNum);
						preg_match('/^(([0-9]{4})\/([0-9]*)(\-[0-9]*)?)$/', $caseNum, $matches);
						if (!isset($matches[1])) {
							var_dump($footer_text);
							var_dump($sivilombudsmannenSaksnummer);
							var_dump($sivilombudsmannenSaksnummer2);
							var_dump($caseNum);
						}
						$npUrl[$matches[1]]
							= 'https://norske-postlister.no/sak/sivilombudsmannen/' . $matches[2] . '/' . $matches[3];
					}
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

			$item = array(
				'datoUttalelse' => $datoUttalelse,
				'datoPublisert' => $datoPublisert,
				'sivilombudsmannenSaksnummer' => $sivilombudsmannenSaksnummer,
				'url' => $node->filter('a')->first()->attr('href'),
				'tittel' => $node->filter('h1')->first()->text('', true),
				'beskrivelse' => $node->filter('.list-item__desc')->first()->text('', true),
				'url-norske-postlister.no' => $npUrl,
			);
			$item['tittel_lovRef'] = getLawReferencesFromText($item['tittel']);
			$item['beskrivelse_lovRef'] = getLawReferencesFromText($item['beskrivelse']);
			return $item;
		}),
		'pages' => $crawler->filter('.pagination__pages li')->each(function (Crawler $node, $i) {
			return $node->text('', true);
		})
	);
}

function getLawReferencesFromText($text) {
	$lawRefs = array();
	$text = strtolower($text);

	$laws = array(
		array(
			'name' => 'Lov om behandlingsmåten i forvaltningssaker (forvaltningsloven)',
			'nicknames' => array(
				'forvaltningsloven',
				'fvl.',
				'forvaltningsloven (fvl.)',
			),
			'link' => 'https://lovdata.no/dokument/NL/lov/1967-02-10'
		),
		array(
			'name' => 'Lov om rett til innsyn i dokument i offentleg verksemd (offentleglova)',
			'shortName' => 'offentleglova',
			'nicknames' => array(
				'offentleglova',
				'offentlighetsloven',
			),
			'link' => 'https://lovdata.no/dokument/NL/lov/2006-05-19-16'
		),
		array(
			'name' => 'Lov om grunnskolen og den vidaregåande opplæringa (opplæringslova)',
			'nicknames' => array(
				'opplæringslova'
			),
			'link' => 'https://lovdata.no/dokument/NL/lov/1998-07-17-61'
		),
		array(
			'name' => 'Lov om eigedomsskatt til kommunane (eigedomsskattelova)',
			'nicknames' => array(
				'eigedomsskattelova'
			),
			'link' => 'https://lovdata.no/dokument/NL/lov/1975-06-06-29'
		),
		array(
			'nicknames' => array(
				'voldsoffererstatningsloven',
				'sosialtjenesteloven',
				'politiregisterloven',
				'politiregisterforskriften',
				'konsesjonsloven',
				'pasient- og brukerrettighetsloven',
				'utlendingsloven',
				'utlendingsforskriften',
				'utlendingsforskrift',
				'inkassoloven',
				'inkassoforskriftens',
				'vegtrafikkloven',
				'sivilombudsmannsloven',
				'sivilombudsmannen',
				'ombudsmannsloven',
				'sivilombudsmannsinstruksen',
				'kommuneloven',
				'forskrift om parkeringstillatelse for forflytningshemmede',
				'plan- og bygningsloven',
				'plan- og bygningsloven (plbl.)',
				'plbl.',
				'psykisk helsevernloven',
				'helsepersonelloven',
				'pasientreiseforskriften',
				'spesialisthelsetjenesteloven',
				'barnehageloven',
				'vergemålsloven',
				'grunnloven',
				'dimensjoneringsforskriften',
				'folketrygdloven',
				'merverdiavgiftsloven',
				'vergemålsforskriften',
'kulturminneloven',
'forurensningsloven',
'skatteloven',
'universitetsloven',
'straffegjennomføringsloven',
'legemiddelloven',
'ligningsloven',
'tinglysingsloven',
'særavgiftsforskriften',
'strukturkvoteforskriften',
'finnmarksloven',
'dokumentavgiftsloven',
'barnelova',
'barneloven',
'forskrift om arbeidsmarkedstiltak',
'produktkontrolloven',
'forsvarspersonelloven',
'børsloven',
'reindriftsloven',
'barnevernloven',
'offentlegforskrifta',
'trafikkopplæringsforskriften',
'konkurranseloven',
'delingsloven',
'arbeidsmiljøloven',

/*
				// Contextual references
				'vilkårene i',
				'jf.',
				' i',
				'etter forskriften',
				'forståelse og anvendelse av',
				'sningsåret 2018 – 2019',
				'er i strid med',
				'jf. forskriften',
				'jf. samme lovs',
*/
			)
		)
	);
	foreach ($laws as $law) {
		foreach ($law['nicknames'] as $nick) {
			// TEST DATA:
			// forvaltningsloven § 24 første ledd
			// forvaltningsloven § 34 annet ledd annet punktum
			// offentleglova § 29 første ledd
			// opplæringslova § 9 A-11
			// eigedomsskattelova § 5 første ledd bokstav h
			// politiregisterforskriften § 53-8
			// inkassolovens § 9
			// inkassoforskriftens § 1-2
			// offentleglova § 5 første ledd og § 24 første ledd
			// kommuneloven § 40 nr. 3 bokstav c første ledd
			$regex = '/((' . preg_quote($nick) . 's?) ?§ ?[0-9]*(\-[0-9]*)*( [A-ZÆØÅ]\-[0-9]*)?( nr\. [0-9]*)?( [a-zæøå]* ledd)?( [a-zæøå]* punktum)?( bokstav [a-zæøå]*)?( [a-zæøå]* ledd)?)/';
			preg_match($regex, $text, $matches);
			while (isset($matches[1])) {
				if (isset($law['shortName'])) {
					// Use common short name
					$lawRefs[] = str_replace($matches[2], $law['shortName'], $matches[1]);
				}
				else {
					$lawRefs[] = $matches[1];
				}
				$text = str_replace($matches[1], $nick, $text);
				$text = str_replace($nick . ' og ', $nick . ' ', $text);
				preg_match($regex, $text, $matches);
			}

			// offentleglova §§ 14 og 15
			$regex = '/(' . $nick . ' §§ [0-9]* og [0-9]*)/';
			preg_match($regex, $text, $matches);
			while (isset($matches[1])) {
				$lawRefs[] = $matches[1];
				$text = str_replace($matches[1], '', $text);
				preg_match($regex, $text, $matches);
			}
		}
	}

	if (str_contains($text, '§')) {
		while (str_contains($text, '§')) {
			$start = max(0, strpos($text, '§') - 50);
			echo substr($text, $start, min(strlen($text) - $start, strpos($text, '§') + 100 - $start)) . "\n";
			$text = substr($text, strpos($text, '§') + 1);
		}
		//throw new Exception('Law reference not picked up.');
	}

	return $lawRefs;
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

