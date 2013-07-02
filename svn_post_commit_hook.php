<?php

$args[] = array();

$datadir    = '';

$svnlook = '/usr/bin/svnlook';
$tmpdir  = getenv('TMPDIR') ? getenv('TMPDIR') : '/tmp';


$dirtemplate = 'svninfo.';// . posix_getpgrp() . '.' . posix_getpid() ;

function create_tmp_dir($tmpdir, $dirtemplate) {

	$datadir = $tmpdir . '/' . $dirtemplate . '-' . rand(0, 99999999);
	mkdir($datadir);
	chdir($datadir);

	return $datadir;
}

function cleanup($datadir) {

	unlink($datadir . '/info.txt');
	unlink($datadir . '/diff.txt');
	rmdir($datadir);
}

function process_args() {

	$soptions = 'r:v:t:f:';
	$loptions = array(
		'repository:',
		'revision:',
		'mailto:',
		'from:'
	);

	$args = getopt($soptions, $loptions);

	if ( count($args) < 3 )
		die('invalid argument count');

	if ( !isset($args['repository']) )
		$args['repository'] = $args['r'];

	if ( !isset($args['revision']) )
		$args['revision'] = $args['v'];

	if ( !isset($args['from']) )
		$args['from'] = $args['f'];

	if ( !isset($args['mailto']) )
		$args['mailto'] = $args['t'];

	if ( !is_numeric($args['revision']) )
		die('revision must be an integer: ' . $args['revision']);

	if ( !is_dir($args['repository']) )
		die('no such directory: ' . $args['repository']);

	unset($args['r']);
	unset($args['v']);
	unset($args['t']);

	return $args;
}

function create_tmp_files($svnlook, $repository, $revision, $datadir) {

	$cmd = $svnlook . ' info "' . $repository . '" -r ' . $revision . ' > ' . $datadir . '/info.txt' ;
	popen($cmd, 'r');

	$cmd = $svnlook . ' diff "' . $repository . '" -r ' . $revision . ' > ' . $datadir . '/diff.txt' ;
	popen($cmd, 'r');
}

function read_tmp($file) {
	global $datadir;

	$ret = false;

	$tmp = $datadir . '/' . $file . '.txt';
	$fd = fopen($tmp, 'r') or die('error opening file [' . $tmp . ']');

	while (!feof($fd)) {
		$rows[] = fgets($fd);
	}
	fclose($fd);

	return $rows;
}

function process_commit($datadir) {

	global $args;

	$info = read_tmp('info');
	$diff = read_tmp('diff');

	//criar corpo do email
	$head['title'] = '';

	$tmp = $datadir . '/info.txt';
	$fd = fopen($tmp, 'r') or die('error opening file [' . $tmp . ']');
	if ( !feof($fd) ) {
		$head['user']    = str_replace("\n", '', fgets($fd));
		$head['date']    = str_replace("\n", '', fgets($fd));
		$head['logsize'] = str_replace("\n", '', fgets($fd));
		$head['log']     = str_replace("\n", '', fgets($fd));

		$args['from']    = $head['user'];
		$args['subject'] = $head['log'];
	}
	fclose($fd);

	$tmp = $datadir . '/diff.txt';
	$fd = fopen($tmp, 'r') or die('error opening file [' . $tmp . ']');

	$i = 0;
	$commit_in = '';

	while ( !feof($fd) ) {
		$row = str_replace("\n", '', fgets($fd));
		if ( $i == 0 ) {
			$tmp = explode(':', $row); $tmp = explode('/', $tmp[1]);
			$commit_in = trim($tmp[0]);
			$head['title'] .= $commit_in;
			$i++;
		}

		$tmp = explode(':', $row);

		if ( count($tmp) > 1 ) {
			if ( strtolower(trim($tmp[1])) == 'svn' ) {
				continue;
			}
		}

		switch ( strtolower($tmp[0]) ) {
			case 'modified' :
				$arq = str_replace($commit_in, '', $tmp[1]);
				$head2[$arq]['lnadd'] = 0;
				$head2[$arq]['lnrem'] = 0;
				$head2[$arq]['from'] = 0;
				$head2[$arq]['to'] = 0;
				$head2[$arq]['mod'] = 0;
				break;
			case 'added':
				$arq = str_replace($commit_in, '', $tmp[1]);
				$head2[$arq]['lnadd'] = 0;
				$head2[$arq]['lnrem'] = 0;
				$head2[$arq]['from'] = 0;
				$head2[$arq]['to'] = 0;
				$head2[$arq]['add'] = 0;
				break;
			case 'deleted':
				$arq = str_replace($commit_in, '', $tmp[1]);
				$head2[$arq]['lnadd'] = 0;
				$head2[$arq]['lnrem'] = 0;
				$head2[$arq]['from'] = 0;
				$head2[$arq]['to'] = 0;
				$head2[$arq]['rem'] = 0;
				break;
			default:
				if ( substr($row, 0, 3) == '---' ) {
					$from = explode('(', $row );
					$from = str_replace('rev ', '', $from[1]);
					$from = str_replace(')', '', $from);
					$head2[$arq]['from'] = $from;
				} elseif ( substr($row, 0, 3) == '+++' ) {
					$to = explode('(', $row );
					$to = str_replace('rev ', '', $to[1]);
					$to = str_replace(')', '', $to);
					$head2[$arq]['to'] = $to;
				} else {
					if ( substr($row, 0, 1) == '+') {
						$head2[$arq]['lnadd'] ++;
					}
					if ( substr($row, 0, 1) == '-') {
						$head2[$arq]['lnrem'] ++;
					}
				}
		}

	}
	fclose($fd);

$body  = "

<html>
<head>
	<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">
</head>
<body>
	<div id=\"body\" style=\"background-color:#ffffff;\" >
		<table cellspacing=\"0\" cellpadding=\"0\" border=\"0\" rules=\"cols\">
			<tr class=\"head\" style=\"border-bottom-width:1px;border-bottom-style:solid;\" >
				<td class=\"headtd\" style=\"padding:0;padding-top:.2em;\" colspan=\"4\">
					Commit in <b><tt>" . $head['title'] . "</tt></b>
				</td>
			</tr>";
$i = 1;
$totmod = 0;
$totadd = 0;
$totrem = 0;

foreach ( $head2 as $arq => $value ) {
	if ( ($i++ % 2) != 0 ) {
		$body .= "
			<tr>";
	} else {
		$body .= "
			<tr class=\"alt\" style=\";\" >";
	}
	if ( $value['lnadd'] != 0 && $value['lnrem'] != 0 ) {
		$body .= "
				<td>
					<tt><a href=\"#" . $arq . "\">" . $arq . "</a></tt>
				</td>
				<td id=\"added\" class=\"headtd2\" style=\"padding-left:.3em;padding-right:.3em; background-color:#ddffdd;\" align=\"right\">+" . $value['lnadd'] . "</td>
				<td id=\"removed\" class=\"headtd2\" style=\"padding-left:.3em;padding-right:.3em; background-color:#ffdddd;\" align=\"right\">-" . $value['lnrem'] . "</td>
				<td class=\"headtd2\" style=\"padding-left:.3em;padding-right:.3em;\" nowrap=\"nowrap\">" . $value['from'] . " -&gt; " . $value['to'] . "</td>";
		$totmod++;
	} elseif ( $value['lnadd'] == 0 ) {
		$body .= "
				<td><tt><a href=\"#" . $arq . "\"><span id=\"removed\" style=\"background-color:#ffdddd;\" >" . $arq . "</span></a></tt></td>
				<td></td>
				<td id=\"removed\" class=\"headtd2\" style=\"padding-left:.3em;padding-right:.3em; background-color:#ffdddd;\" align=\"right\">-" . $value['lnrem'] . "</td>
				<td class=\"headtd2\" style=\"padding-left:.3em;padding-right:.3em;\" nowrap=\"nowrap\">" . $value['from'] . " removed</td>";
		$totadd++;
	} elseif ( $value['lnrem'] == 0 ) {
		$body .= "
				<td><tt><a href=\"#" . $arq . "\"><span id=\"addedalt\" style=\"background-color:#ccf7cc;\" >" . $arq . "</span></a></tt></td>
				<td id=\"addedalt\" class=\"headtd2\" style=\"padding-left:.3em;padding-right:.3em; background-color:#ccf7cc;\" align=\"right\">+" . $value['lnadd'] . "</td><td></td>
				<td class=\"headtd2\" style=\"padding-left:.3em;padding-right:.3em;\" align=\"right\" nowrap=\"nowrap\">added " . $value['to'] . "</td>";
		$totrem++;
	}

	$body .= "
			</tr>";
}
$body .= "
		</table>
		<small id=\"info\" style=\"color: #888888;\" >" . $totadd . " added + " . $totrem . " removed + " . $totmod . " modified, total " . ($totadd+$totrem+$totmod) . " files</small><br />
		<pre class=\"comment\" style=\"
			white-space:-moz-pre-wrap;
			white-space:-pre-wrap;
			white-space:-o-pre-wrap;
			white-space:pre-wrap;
			word-wrap:break-word;
			padding:4px;
			border:1px dashed #000000;
			background-color:#ffffdd;\" >" . $head['log'] . "</pre>
			";

$tmp = $datadir . '/diff.txt';
$fd = fopen($tmp, 'r') or die('error opening file [' . $tmp . ']');


$property = false;

$first = true;
while ( !feof($fd) ) {
	$row = str_replace("\n", '', fgets($fd));

	$tmp = explode(':', $row);

	//if ( count($tmp) > 1 ) {

		switch ( strtolower(trim($tmp[0])) ) {
			case 'property changes on':
				$property = true;
				break;
			case 'modified':
			case 'added':
				if ( $tmp[1] == 'svn' ) {
					break;
				}
			case 'deleted':
				$property = false;
				if ( ! $first ) {
					$body .= "		</div>\n";
				} else {
					$first = false;
				}
				$arq = str_replace($commit_in, '', $tmp[1]);
				$body .= "
		<hr />
		<a name=\"" . $arq . "\" />
		<div class=\"file\" style=\"border:1px solid #eeeeee;margin-top:1em;margin-bottom:1em;\" >
			<span class=\"pathname\" style=\"font-family:monospace; float:right;\" >" . $commit_in . "</span><br />
			<div class=\"fileheader\" style=\"margin-bottom:.5em;\" >
				<big><b>" . $arq . "</b></big>&nbsp;
				<small id=\"info\" style=\"color: #888888;\" >" . $head2[$arq]['from'] . " -&gt; " . $head2[$arq]['to'] . "</small>
			</div>";
				break;
			default:
				if ( substr($row, 0, 3) == '===' ) {
					continue;
				} elseif ( substr($row, 0, 3) == '---' ) {
					$body .= "
			<pre class=\"diff\" style=\"margin:0;\" ><small id=\"info\" style=\"color: #888888;\" >" . $row . "<br>" . fgets($fd) . "<br>" . fgets($fd) . "<br></small></pre>";
				} elseif ( substr($row, 0, 1) == ' ' ) {
					$body .= "
			<pre id=\"context\" class=\"diff\" style=\"margin:0; background-color:#eeeeee;\" >" . htmlentities($row) . "</pre>";
				}  elseif ( substr($row, 0, 1) == '-' ) {
					$body .= "
			<pre id=\"removed\" class=\"diff\" style=\"margin:0; background-color:#ffdddd;\" >" . htmlentities($row) . "</pre>";
				}  elseif ( substr($row, 0, 1) == '+' ) {
					$body .= "
			<pre id=\"added\" class=\"diff\" style=\"margin:0; background-color:#ddffdd;\" >" . htmlentities($row) . "</pre>";
				}  elseif ( substr($row, 0, 1) == '@' ) {
					$body .= "
			<pre class=\"diff\" style=\"margin:0;\" ><small id=\"info\" style=\"color: #888888;\" >" . htmlentities($row) . "</small></pre>";
				}
		}

	//}

}


$body .= "
</div>
<center><small><a href=\"http://github.com/vitormattos\">SVNspam</a></small></center>
</body>
</html>
";

return $body;

}

function send_email($body) {
	global $args;

	$from = ucfirst($args['from']);
	$to   = $args['mailto'];
	$headers =	'From: ' . $from . "\r\n";
	$headers .= "Content-type: text/html; charset=UTF-8\r\n";

	$tmp = explode('/', $args['repository']);
	$tmp = $tmp[count($tmp)-1];

	$subject = '[SVN ' . $tmp . '] ' . $args['subject']; //substr($args['subject'], 0, 30);

	if ( ! mail($to, $subject, $body, $headers) )
		echo "error sending mail";
}

$datadir = create_tmp_dir($tmpdir, $dirtemplate);

$args = process_args();
$revision = $args['revision'];
$repository = $args['repository'];

create_tmp_files($svnlook, $repository, $revision, $datadir);

$body = process_commit($datadir);

send_email($body);

cleanup($datadir);
