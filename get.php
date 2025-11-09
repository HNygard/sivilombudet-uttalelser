<?php
/**
 * This script downloads all uttalelser from Sivilombudet
 *
 * Based on code from Norske-postlister.no
 *
 * @author Hallvard Nygård, @hallny
 */

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/vendor/autoload.php';
use Symfony\Component\DomCrawler\Crawler;

$cacheTimeSeconds = 60 * 60 * 24 * 4;
$cache_location = __DIR__ . '/cache';
$baseUrl = 'https://www.sivilombudet.no/uttalelser/';
$updateDate = date('d.m.Y H:i:s');

$lawsNotPresent = array();

mkdirIfNotExists($cache_location);

$mainPage = getUrlCachedUsingCurl($cacheTimeSeconds, $cache_location . '/page-1.html', $baseUrl);
$items = readItems($mainPage);

$obj = new stdClass();
$lastPage = (int)end($items['pages']);
$obj->pageCount = $lastPage;
$obj->itemCount = 0;
$obj->lastUpdated = $updateDate;
$obj->sourceInfo = "Datasett hentet fra https://hnygard.github.io/sivilombudet-uttalelser/, laget av @hallny / Norske-postlister.no. Kilde: $baseUrl";
$obj->items = $items['items'];

for ($pageNum = 2; $pageNum <= $lastPage; $pageNum++) {
	$pageHtml = getUrlCachedUsingCurl($cacheTimeSeconds, $cache_location . '/page-' . $pageNum . '.html', $baseUrl . 'page/' . $pageNum . '/');
	$items2 = readItems($pageHtml);
	$obj->items = array_merge($obj->items, $items2['items']);
}

$obj->itemCount = count($obj->items);

if ($obj->itemCount < 1000) {
	throw new Exception('Too few items found: ' . $obj->itemCount);
}

file_put_contents(__DIR__ . '/sivilombudet-uttalelser.json', json_encode($obj, JSON_PRETTY_PRINT ^ JSON_UNESCAPED_SLASHES ^ JSON_UNESCAPED_UNICODE));

ksort($lawsNotPresent);
foreach ($lawsNotPresent as $word => $count) {
	if ($count < 100) {
		continue;
	}
	echo "// $count\n";
	echo "'$word',\n";
}

function htmlHeading($title = 'Sivilombudets uttalelser') {
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
table tr.not-matching-law {
    display:none;
}
</style>";
}

$html = htmlHeading() . "

<h1>Sivilombudets uttalelser</h1>\n";
$html .= "Laget av <a href='https://twitter.com/hallny'>@hallny</a> / <a href='https://norske-postlister.no'>Norske-postlister.no</a><br>\n";
$html .= "<a href='https://github.com/HNygard/sivilombudet-uttalelser/'>Kildekode for oppdatering av denne lista</a> (Github)<br><br>\n\n";
$html .= '
<ul>
	<li>Antall uttalelser: ' . $obj->itemCount . '</li>
	<li>Liste sist oppdatert: ' . $updateDate . '</li>
	<li>Kilde: <a href="' . $baseUrl . '">' . $baseUrl . '</a></li>
	<li>JSON-format: <a href="./sivilombudet-uttalelser.json">sivilombudet-uttalelser.json</a></li>
	<li>CSV-format (Excel): <a href="./sivilombudet-uttalelser.csv">sivilombudet-uttalelser.csv</a></li>
</ul>

<table>
	<thead>
		<tr>
			<th>Dato - uttalelse (publisert)</th>
			<th>Saksnummer</th>
			<th>Uttalelse</th>
			<th>
			    Referanser til lov<br>
			    <input id="law-filter" type="text"> - Filter
			</th>
			<th>Tittel</th>
		</tr>
	</thead>
';
$csv = "Datasett hentet fra;https://hnygard.github.io/sivilombudet-uttalelser/;@hallny / Norske-postlister.no;Kilde;$baseUrl;Data hentet;$updateDate\n";
$csv .= "Dato - uttalelse;Dato - publisert;Saksnummer;Lenke uttalelse;Lovreferanse;Tittel\n";
foreach ($obj->items as $item) {
	$urls = array();
	$saksnummer = array();
	if ($item['url-norske-postlister.no'] != null) {
		foreach ($item['url-norske-postlister.no'] as $caseNum => $url) {
			$urls[] = '<a href="'.$url.'"><img src="https://norske-postlister.no/favicon-16x16.png"> SM-' . $caseNum . '</a>';
			$saksnummer[] = 'SM-' . $caseNum;
		}
	}
	$lovReferanser = array();
	if (count($item['tittel_lovRef']) > 0) {
		foreach($item['tittel_lovRef'] as $lovRef) {
			$lovReferanser[$lovRef] = $lovRef;
		}
	}
	if (count($item['beskrivelse_lovRef']) > 0) {
		foreach($item['beskrivelse_lovRef'] as $lovRef) {
			$lovReferanser[$lovRef] = $lovRef;
		}
	}
	if (count($item['uttalelse_lovRef']) > 0) {
		foreach($item['uttalelse_lovRef'] as $lovRef) {
			$lovReferanser[$lovRef] = $lovRef;
		}
	}
	ksort($lovReferanser);

	$html .= '
	<tr>
		<th>' . $item['datoUttalelse'] . ' <span style="font-weight: normal;">(' . $item['datoPublisert'] . ')</span></th>
		<td>' . implode(',<br>' . chr(10), $urls) . '</td>
		<td>[<a href="' . $item['url'] . '">Til uttalelse</a>]</td>
		<td class="law-ref">' . implode("<br>\n", $lovReferanser) . '</td>
		<td>' . $item['tittel'] . '</td>
	</tr>
';
	$csv .= $item['datoUttalelse'] . ';'
		. $item['datoPublisert'] . ';'
		. implode(', ', $saksnummer) . ';'
		. $item['url'] . ';'
		. implode(', ', $lovReferanser) . ';'
		. str_replace(';', ':', $item['tittel']) . "\n";
}

$html .= '
</table>

<script>
	var timeout;
	document.getElementById(\'law-filter\').onkeyup = function() {
		var search = this.value;
		clearTimeout(timeout);
		timeout = setTimeout(function() {
			var trs = document.getElementsByTagName(\'tr\');
			for (var i = 1; i < trs.length; i++) {
				var tr = trs[i];
				var notes = null;
				for (var o = 0; o < tr.childNodes.length; o++) {
					if (tr.childNodes[o].className == "law-ref") {
						notes = tr.childNodes[o];
						break;
					}
				}
				if (notes !== null) {
					var patt = new RegExp(search + \'[^0-9]\', \'g\');
					if (search == \'\' || patt.test(notes.innerHTML)) {
						tr.className = \'matching-law\';
					}
					else {
						tr.className = \'not-matching-law\';
					}
				}
			}
		}, 250);
	};
</script>
';

file_put_contents(__DIR__ . '/index.html', $html);
file_put_contents(__DIR__ . '/sivilombudet-uttalelser.csv', $csv);


function readItems($html) {
	$crawler = new Crawler($html);
	return array(
		'items' => $crawler->filter('main article.post-uttalelser')->each(function (Crawler $node, $i) {
			$smUrl = $node->filter('a')->first()->attr('href');
			if (!str_starts_with($smUrl, '')) {
				throw new Exception('Unknown URL: ' . $smUrl);
			}
			$cacheFile = 'uttalelse-' . str_replace('https://', '', $smUrl) . '.html';
			$cacheFile = str_replace('/', '---', $cacheFile);
			global $cacheTimeSeconds, $cache_location;
			$articleHtml = getUrlCachedUsingCurl($cacheTimeSeconds, $cache_location . '/' . $cacheFile, $smUrl);
			$crawlerArt = new Crawler($articleHtml);

			$footer_text = $node->filter('.post-meta p')->each(function (Crawler $node, $i) {
			    return $node->text('', true);
			});
			if (empty($footer_text)) {
				throw new Exception('No footer text found.');
			}
			$datoUttalelse = null;
			$datoPublisert = null;
			$sivilombudetSaksnummer = null;
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
                    $text = str_replace('Saksnummer: 20/', 'Saksnummer: 2020/', $text);
                    $text = str_replace('Saksnummer: 2024/1381, 2024/ 1817 og 2024/2588', 'Saksnummer: 2024/1381 2024/1817 2024/2588', $text);
					$sivilombudetSaksnummer = trim(substr($text, strlen('Saksnummer: ')));

					// Clean up content
					$sivilombudetSaksnummer2 = str_replace('sak ', '', $sivilombudetSaksnummer);
					$sivilombudetSaksnummer2 = str_replace('Sak ', '', $sivilombudetSaksnummer2);
					$sivilombudetSaksnummer2 = str_replace('tidl.', '', $sivilombudetSaksnummer2);
					$sivilombudetSaksnummer2 = str_replace('tidligere ', '', $sivilombudetSaksnummer2);
					$sivilombudetSaksnummer2 = str_replace(' og ', ' ', $sivilombudetSaksnummer2);
					$sivilombudetSaksnummer2 = str_replace(', ', ' ', $sivilombudetSaksnummer2);
					$sivilombudetSaksnummer2 = str_replace('(', '', $sivilombudetSaksnummer2);
					$sivilombudetSaksnummer2 = str_replace(')', '', $sivilombudetSaksnummer2);
					$npUrl = array();
					foreach (explode(' ', $sivilombudetSaksnummer2) as $caseNum) {
						$caseNum = trim($caseNum);
						preg_match('/^(([0-9]{4})\/([0-9]*)(\-[0-9]*)?)$/', $caseNum, $matches);
						if (!isset($matches[1])) {
							var_dump($footer_text);
							var_dump($sivilombudetSaksnummer);
							var_dump($sivilombudetSaksnummer2);
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
				elseif (
					$text == 'Dato for uttalelse:'
					|| $text == 'Saksnummer:'
				) {
					// Ignore
				}
				else {
					var_dump($footer_text);
					throw new Exception('Unknown: ' . $text);
				}
			}

			$item = array(
				'datoUttalelse' => $datoUttalelse,
				'datoPublisert' => $datoPublisert,
				'sivilombudetSaksnummer' => $sivilombudetSaksnummer,
				'url' => $smUrl,
				'tittel' => $node->filter('h1')->first()->text('', true),
				'beskrivelse' => $node->filter('.list-item__desc')->first()->text('', true),
				'url-norske-postlister.no' => $npUrl,
				'uttalelse' => $crawlerArt->filter('article')->first()->text('', true)
			);
			$item['tittel_lovRef'] = getLawReferencesFromText($item['tittel']);
			$item['beskrivelse_lovRef'] = getLawReferencesFromText($item['beskrivelse']);
			$item['uttalelse_lovRef'] = getLawReferencesFromText($item['uttalelse']);
			return $item;
		}),
		'pages' => $crawler->filter('.pagination li')
			->reduce(fn (Crawler $n) => $n->text('', true) !== 'Neste')
			->each(function (Crawler $node, $i) {
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
			'shortName' => 'forvaltningsloven',
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
				'offentlighetslov',
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
			'name' => 'Lov om planlegging og byggesaksbehandling (plan- og bygningsloven)',
			'shortName' => 'plan- og bygningsloven',
			'nicknames' => array(
				'plan- og bygningsloven',
				'plan- og bygningsloven (plbl.)',
				'plbl.',
			),
			'link' => 'https://lovdata.no/dokument/NL/lov/2008-06-27-71'
		),
		array(
			'nicknames' => array(
				'voldsoffererstatningsloven',
				'voldsoffererstatningsforskriften',
				'voel.',
				'voldsofferstatningsloven',

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
				'sivilombudet',
				'ombudsmannsloven',
				'sivilombudsmannsinstruksen',
				'kommuneloven',
				'forskrift om parkeringstillatelse for forflytningshemmede',
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
'yrkesbefalloven',
// 1
'yrkeskadeforsikringsloven',
// 3
'yrkesskadeforsikringsloven',
// 4
'yrkestransporforskriften',
// 34
'yrkestransportforskriften',
// 83
'yrkestransportlova',
// 17
'yrkestransportloven',
'våpenloven',
'våpenforskriften',

'viltloven',
'trossamfunnsloven',
'opplæringsforskriften',

'oppll',
'opplæringsloven',

'pasientjournalloven',
// 9
'pasientrettighetsloven',
// 11
'pasientskadeloven',
'passloven',
'omsorgstjenesteloven',
'naturmangfoldloven',
// 21
'naturskadeloven',
// 17
'nav-loven',
'kommunehelsetjenesteloven',

// 123
'politiloven',
// 104
'statsborgerloven',
// 146
'straffeprosessloven',

'allmennaksjeloven',
'aksjeloven',
'miljøinformasjonsloven',
// 780
'vannressursloven',
// 624
'trygderettsloven',
// 624
'straffeloven',
// 312
'sivilombudsloven',
// 156
'merverdiavgiftsforskriften',
// 156
'kraftberedskapsforskriften',
// 156
'(vannressursloven)',
// 624
'arkivforskrifta',
// 156
'arkivforskriften',
// 156
'arkivlova',
// 156
'beskyttelsesinstruksen',
// 2028
'damsikkerhetsforskriften',
// 624
'forskriften',
// 312
'forvaltningslov',
// 156
'forvaltningslovforskriften',
// 780
'helsevernforskriften',
// 156
'(arkivforskriften)',

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
            // offentleglova §§ 14 og 15
            $regex = '/(' . $nick . ' §§ ([0-9]*) og ([0-9]*))/';
            preg_match($regex, $text, $matches);
            while (isset($matches[1])) {
                $text = str_replace($matches[1],
                    $nick . ' § ' . $matches[2]
                    . ' og '
                    . $nick . ' § ' . $matches[3] , $text);
                preg_match($regex, $text, $matches);
            }

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
			// offentleglova § 32, fjerde ledd
			// miljøinformasjonsloven § 2 (1) (b)
			$regex = '/((' . preg_quote($nick) . 's?) ?§ ?[0-9]*(\-[0-9]*)*( [A-ZÆØÅ]\-[0-9]*)?( nr\. [0-9]*)?( [a-zæøå]* ledd)?( [a-zæøå]* punktum)?( bokstav [a-zæøå]*)?( [a-zæøå]* ledd)?( ?\([0-9a-zA-ZæøåÆØÅ]{0,4}\))*)/';
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
		}
	}

	if (str_contains($text, '§')) {
		while (str_contains($text, '§')) {
			$start = max(0, strpos($text, '§') - 50);
			//echo substr($text, $start, min(strlen($text) - $start, strpos($text, '§') + 100 - $start)) . "\n";
			$textBeforeParagraf = trim(substr($text, 0, strpos($text, '§')));
			$wordBefore = trim(strrchr($textBeforeParagraf, ' '));
			//echo "\t\t$wordBefore\n";
			global $lawsNotPresent;
			if (!isset($lawsNotPresent[$wordBefore])) {
				$lawsNotPresent[$wordBefore] = 0;
			}
			$lawsNotPresent[$wordBefore]++;
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

    if (!str_starts_with($header, 'HTTP/2 200')) {
        if (str_starts_with($header, 'HTTP/2 404 Not Found') && file_exists($cache_file)) {
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

