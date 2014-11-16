<?php
/*
 * HYPERFORK, Y'ALL
 *
 *
 * Copyright (c) 2014, CASH Music
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that the following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list
 * of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this
 * list of conditions and the following disclaimer in the documentation and/or other
 * materials provided with the distribution.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT
 * NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA,
 * OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
 * LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE
 * OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
 * OF THE POSSIBILITY OF SUCH DAMAGE.
 */


// copied this shit from CASHSystem so we can get consistency without the full library
function getURLContents($data_url,$post_data=false,$ignore_errors=false) {
	$url_contents = false;
	$user_agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.5; rv:7.0) Gecko/20100101 Firefox/7.0';
	$do_post = is_array($post_data);
	if ($do_post) {
		$post_query = http_build_query($post_data);
		$post_length = count($post_data);
	}
	if (ini_get('allow_url_fopen')) {
		// try with fopen wrappers
		$options = array(
			'http' => array(
				'protocol_version'=>'1.1',
				'header'=>array(
					'Connection: close'
				),
				'user_agent' => $user_agent
			));
		if ($do_post) {
			$options['http']['method'] = 'POST';
			$options['http']['content'] = $post_query;
		} 
		if ($ignore_errors) {
			$options['http']['ignore_errors'] = true;
		}
		$context = stream_context_create($options);
		$url_contents = @file_get_contents($data_url,false,$context);
	} elseif (in_array('curl', get_loaded_extensions())) {
		// fall back to cURL
		$ch = curl_init();
		$timeout = 20;
		
		@curl_setopt($ch,CURLOPT_URL,$data_url);
		if ($do_post) {
			curl_setopt($ch,CURLOPT_POST,$post_length);
			curl_setopt($ch,CURLOPT_POSTFIELDS,$post_query);
		}
		@curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
		@curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		@curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,$timeout);
		@curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		@curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		@curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		@curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		if ($ignore_errors) {
			@curl_setopt($ch, CURLOPT_FAILONERROR, false);
		} else {
			@curl_setopt($ch, CURLOPT_FAILONERROR, true);
		}
		$data = curl_exec($ch);
		curl_close($ch);
		$url_contents = $data;
	}
	return $url_contents;
}

/*
 * LIVE SWITCH
 * Set ?live=whatever to switch from cached data to live data feeds
 *
 */
if (isset($_GET['live'])) {
	// get JSON from hypemachine
	$hypem_json = getURLContents('http://hypem.com/playlist/popular/3day/json/[pagenumber]/data.js');
	
	// get Pitchfork tracks RSS and push it to JSON
	$pitchfork_rss = simplexml_load_string(getURLContents('http://pitchfork.com/rss/reviews/tracks/'));
	$pitchfork_json = json_encode($pitchfork_rss);
} else {
	$hypem_json = file_get_contents(__DIR__ . '/sampledata/hypem.json');
	$pitchfork_json = file_get_contents(__DIR__ . '/sampledata/pitchfork.json');
}

// decode the JSON to associative arrays
$hypem_feed = json_decode($hypem_json,true);
$pitchfork_feed = json_decode($pitchfork_json,true);

// empty array to store our feed
$full_feed = array();

// some data normalization
$replace = array(',','(',')','\'',' ','"','’');

foreach ($hypem_feed as $item) {
	// TO-DOs: 
	//      1. mix adds and hearts into the hypemscore
	$key = strtolower(substr(str_replace($replace,'',$item['artist']),0,8)) . strtolower(substr(str_replace($replace,'',$item['title']),0,8));
	if (is_array($item)) {
		$full_feed[$key] = array(
			'date' => $item['dateposted'],
			'key' => $key,
			'total_score' => $item['dateposted'],
			'hypemscore' => $item['dateposted'],
			'pitchforkscore' => 0,
			'artist' => $item['artist'],
			'title' => $item['title'],
			'hypemdata' => $item,
			'pitchforkdata' => ''
		);
	}
}

foreach ($pitchfork_feed['channel']['item'] as $item) {
	// TO-DOs: 
	//      1. check to see if the key matches an existing key — if so add to instead of adding a new entry
	//      2. maybe grab BNM feed and compare to see if the track is present there, affect score
	$splat = explode(':', $item['title']);
	$key = strtolower(substr(str_replace($replace,'',$splat[0]),0,8)) . strtolower(substr(str_replace($replace,'',$splat[1]),0,8));
	if (is_array($item)) {
		$full_feed[$key] = array(
			'date' => strtotime($item['pubDate']),
			'key' => $key,
			'total_score' => strtotime($item['pubDate']),
			'hypemscore' => 0,
			'pitchforkscore' => strtotime($item['pubDate']),
			'artist' => $splat[0],
			'title' => str_replace('"','',$splat[1]),
			'hypemdata' => '',
			'pitchforkdata' => $item
		);
	}
}

// weird sorty bullshit — we split out an array of key => score, sort by that and reorder our array by score
$score = array();
foreach ($full_feed as $key => $item)
{
    $score[$key] = $item['total_score'];
}
array_multisort($score, SORT_DESC, $full_feed);
?>

<!DOCTYPE html>
<head> 
<title>CASH Music</title>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
<link rel="icon" type="image/x-icon" href="https://91ee07a61ca29df61569-b2dba7dce06e8a9c0977ad3a8844e9c8.ssl.cf2.rackcdn.com/v3/ui/default/assets/images/favicon.ico" />
<link href='//fonts.googleapis.com/css?family=Montserrat:400,700' rel='stylesheet' type='text/css'>
<link href='css/mobile.css' rel='stylesheet' type='text/css'>
</head> 
<body>
<header>
<img src="images/bg.jpg" alt="Background" />
<h1>Open <span>Mobile Music</span> Discovery</h1>
</header><!--bg-->
	<div id="mainspc">

		<?php
			// quickie dump
			//
			// TODO:
			//    1. add spans with data attributes for key
			//	  2. load more info on span click, display data from the fullFeed JS object below
			//    3. party
			foreach ($full_feed as $item) {
				echo("<div class='item'><img class='packshot' src='images/packshot.jpg' alt='Track Packshot'/><div class='info'><span class='key'>" . $item['key'] . '</span><!--key-->' . $item['artist'] . " - <a href='/' target='_blank'>" . $item['title'] . "</a> (" . $item['total_score'] . ")</div><!--info--></div><!--item-->\n");
			}
		?>

	</div>

	<script type="text/javascript">
		// for later
		var fullFeed = <?php echo json_encode($full_feed); ?>;
		console.log(fullFeed);
	</script>
</body> 
</html>