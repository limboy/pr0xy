<?php
require 'vendor/http_lite.php';
require 'vendor/phpQuery/phpQuery.php';

//如果服务端不启用https的话，将这个值设为false
$server_use_https = true;

$g_schema = ($_SERVER['REMOTE_ADDR'] == '127.0.0.1')?'http':(($server_use_https)?'https':'http');

$g_url = base64_decode($_POST['url']);
if (strpos($g_url, 'youtube.com') !== false)
{
	die('这个链接就不要尝试了，换一个吧');
}
$g_content = '';

if (substr($g_url,0,7) !== 'http://')
{
	$g_url = 'http://'.$g_url;
}

$is_url = (bool) filter_var($g_url, FILTER_VALIDATE_URL, FILTER_FLAG_HOST_REQUIRED);

$is_url OR die('invalid url');

$parsed_url = parse_url($g_url);
$g_host = $parsed_url['host'];
$g_path = $parsed_url['path'];

if (!file_exists('db/db.sqlite')) 
{
	copy('db/db_sample.sqlite', 'db/db.sqlite');
}

$dbh = new PDO('sqlite:db/db.sqlite');
$sql = 'SELECT * FROM url WHERE url="'.$g_url.'"';
$row = $dbh->query($sql)->fetch(PDO::FETCH_ASSOC);
if (!$row)
{
	$sql = 'INSERT INTO url(url) VALUES("'.$g_url.'")';
	$result = $dbh->exec($sql);
	$g_id =  $dbh->lastInsertId();
}
else {
	$g_id =  $row['id'];
	header('location:show.php/'.$g_id);
	exit;
}


try {
	$content = remote::get($g_url);
}
catch(Exception $e) {
	echo $e->getMessage();
	exit;
}

deal_content($content);

if (!file_exists('cache/'.$g_id))
{
	mkdir('cache/'.$g_id, 0777);
	chmod('cache/'.$g_id, 0777);
}

file_put_contents('cache/'.$g_id.'/index.html', $g_content);
$redirect_url = 'show.php/'.$g_id;
/*
echo <<<HTML
	<html> <head> <meta http-equiv="REFRESH" content="0;url={$redirect_url}"></head> </html>
HTML;
//*/
header('location:show.php/'.$g_id);
exit;

function deal_content($content)
{
	global $g_content;
	$g_content = phpQuery::newDocument($content);
	foreach(pq('link') as $link)
	{
		$src = pq($link)->attr('href');
		$new_src = fetch_resource($src);
		if (!empty($new_src))
			pq($link)->attr('href', $new_src);
	}

	foreach(pq('script') as $script)
	{
		if (strpos(pq($script)->html(), '_getTracker') !== false OR strpos(pq($script)->html(), 'google-analytics.com/ga.js') !== false)
		{
			pq($script)->remove();
			continue;
		}
		$src = pq($script)->attr('src');
		$new_src = fetch_resource($src);
		if (!empty($new_src))
			pq($script)->attr('src', $new_src);
	}

	foreach (pq('img') as $img)
	{
		$src = pq($img)->attr('src');
		$new_src = fetch_resource($src);
		if (!empty($new_src))
			pq($img)->attr('src', $new_src);
	}
}

function deal_css($css_file)
{
	$css_content = file_get_contents($css_file);
	$css_content = preg_replace_callback('/url[ ]*\((.*)\)/', 'fetch_css', $css_content);
	file_put_contents($css_file, $css_content);
}

function fetch_css($css_img)
{
	$css_img = str_replace(array("'",'"'), '', $css_img[1]);
	return 'url('.fetch_resource($css_img).')';
}

function fetch_resource($url)
{
	global $g_path, $g_host, $g_id, $g_schema;
	$real_url = $url;
	if (strpos(str_replace($g_host, '', $url), '.') === false OR strpos($url, '.rss') !== false)
		return $url;
	if (substr($url, 0, 7) !== 'http://')
	{
		if (substr($url, 0, 3) == '../')
		{
			$url = str_replace('\\', '/', $url);
			$url_segment = explode('../', $url);
			$file_path = $url_segment[count($url_segment) - 1];
			$path = dirname($g_path);
			for($i=0; $i<count($url_segment)-1; $i++)
			{
				$path = dirname($path);
			}
			$real_url = 'http://'.$g_host.$path.'/'.$file_path;
		}
		elseif ($url[0] == '/')
		{
			$real_url = 'http://'.$g_host.$url;
		}
		else {
			$real_url = 'http://'.$g_host.'/'.$url;
		}
	}
	else {
		$host = parse_url($url,PHP_URL_HOST);
		if ($host != $g_host)
		{
			/*
			$no_www_host = str_replace('www', '', $g_host);
			if (strpos($host, $no_www_host) === false)
				return ;
			else
			{
				$sub_host = $host;
			}
			//*/
			$sub_host = $host;
		}
	}
	try {
		$content = remote::get($real_url,array(
			CURLOPT_BINARYTRANSFER => 1,
		));

		if ($content === false)
			return;
	}
	catch (Exception $e)
	{
		return $real_url;
	}
	$pathinfo = pathinfo(str_replace('http://'.$g_host.'/', '', $real_url));
	if (!empty($sub_host))
		$pathinfo = pathinfo(str_replace('http://'.$sub_host.'/', '', $real_url));
	$dirname = $pathinfo['dirname'];
	preg_match('/^([^\.]+\.[a-zA-Z0-9]{2,5}).*/', $pathinfo['basename'], $match);
	if (count($match) < 2)
		return $real_url;
	$basename = $match[1];
	$dest_dir = 'cache/'.$g_id.'/'.$dirname;
	if (!file_exists($dest_dir))
	{
		mkdir('cache/'.$g_id.'/'.$dirname, 0777, true);
		chmod('cache/'.$g_id, 0777);
		chmod('cache/'.$g_id.'/'.$dirname, 0777);
	}
	$fp = fopen($dest_dir.'/'.$basename, 'w');
	fwrite($fp, $content);
	fclose($fp);
	if (strpos($basename, '.css') !== false)
	{
		deal_css($dest_dir.'/'.$basename);
	}
	$repalce_url = $_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME']).'/cache/'.$g_id;
	if ($g_schema == 'https')
	{
		$real_url = str_replace('http://', 'https://', $real_url);
	}
	if (empty($sub_host))
		return str_replace($g_host, $repalce_url, $real_url);
	return str_replace($sub_host, $repalce_url, $real_url);
}
