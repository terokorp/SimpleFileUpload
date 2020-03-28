<?php
require("config.php");


if(!function_exists('mb_strlen')) {
	echo("mbstring is not installed");
	header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
	die();
}

function random_str($length, $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_')
{
	$str = '';
	$max = mb_strlen($keyspace, '8bit') - 1;

	if(function_exists('random_int')) {
		for ($i = 0; $i < $length; ++$i) {
			$str .= $keyspace[random_int(0, $max)];
		}
	}
	else {
		for ($i = 0; $i < $length; ++$i) {
			$str .= $keyspace[mt_rand(0, $max)];
		}
	}
	return $str;
}

function createthumb($name, $filename, $new_w, $new_h)
{
	$src_img = imagecreatefromstring(file_get_contents($name));
	$old_x   = imageSX($src_img);
	$old_y   = imageSY($src_img);
	if ($old_x > $old_y) {
		$thumb_w = $new_w;
		$thumb_h = $old_y * ($new_h / $old_x);
	}
	if ($old_x < $old_y) {
		$thumb_w = $old_x * ($new_w / $old_y);
		$thumb_h = $new_h;
	}
	if ($old_x == $old_y) {
		$thumb_w = $new_w;
		$thumb_h = $new_h;
	}
	$dst_img = ImageCreateTrueColor($thumb_w, $thumb_h);
	imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $thumb_w, $thumb_h, $old_x, $old_y);

	imagepng($dst_img, $filename);

	imagedestroy($dst_img);
	imagedestroy($src_img);
}

function buildpage($file, $variables) {
	if(is_file("./static/".$file)) {
		$content = file_get_contents("./static/".$file);
		foreach($variables as $k => $v)
			$content = str_replace('{'.$k.'}', $v, $content);
		print $content;
	} else echo $file. " not found";
}


if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
	$ip = $_SERVER['HTTP_CLIENT_IP'];
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
	$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
} else {
	$ip = $_SERVER['REMOTE_ADDR'];
}

$scriptname = ($_SERVER['SCRIPT_NAME'] ? $_SERVER['SCRIPT_NAME'] : "");
$requesturi =  ($_SERVER['REQUEST_URI']  ? $_SERVER['REQUEST_URI'] : "");
$scriptfolder = ltrim(dirname($scriptname),'/')."/";
if (substr($requesturi, 0, strlen($scriptfolder)) == $scriptfolder) {
	$param = substr($requesturi, strlen($scriptfolder));
	$pos = strpos($param, "?");
	if(!empty($pos)) $param = substr($param, 0, $pos);
	$param = urldecode($param);
	if($param=="upload.php") $param ="";
}

if ($param == "robots.txt") { die("User-agent: *\nDisallow: /"); }

if(isset($_FILES['f']) && $param == "") {
	$log = date('c')." ".$ip;
	$response = array();

	if($_FILES['f']['error'] != UPLOAD_ERR_OK){
		$log .= " file upload error ".$_FILES['f']['error'];
		if($_FILES['f']['error'] == UPLOAD_ERR_INI_SIZE) $response['error'] = "Max file size is ".ini_get('upload_max_filesize')."Mb";
		else $response['error'] = "file_error";
	}

	$ext = pathinfo($_FILES['f']['name'], PATHINFO_EXTENSION);

	if(preg_match("/^[a-zA-Z0-9]+$/", $ext) != 1) {
		$log .= " invalid extension \"$ext\"";
		$response['status']="error";
		$response['statusmessage']="invalid extension $ext";
	}
	elseif(is_uploaded_file($_FILES['f']['tmp_name'])) {
		$foldername = makefoldername($ip, $_FILES['f']['name']);
		$filename = makefilename($ip, $_FILES['f']['name']);

		$jsonfile = $datafolder.$foldername.$filename.".json";
		$datafile = $datafolder.$foldername.$filename.".data";
		$thumbfile = $datafolder.$foldername.$filename.".thumb.png";

		mkdir($datafolder.$foldername, 0777, true);
		$matches = false;
		foreach ($allowedtypes as $pattern) {
			if (preg_match($pattern, $_FILES['f']['type']) == 1) {
				$matches = true;
			}
		}

		if(!$matches) {
			$log .= " invalid filetype ".$_FILES['f']['type'];
			$response['error'] = "invalid filetype ".$_FILES['f']['type'];
		}
		elseif(preg_match('/(base64|eval|script)/i',file_get_contents($_FILES['f']['tmp_name'])) == 1) {
			$response['error'] = "invalid file";
			$log .= " invalid file";
		}
		elseif(move_uploaded_file($_FILES['f']['tmp_name'], $datafile)) {
			$log .= " saved";

			@$imageinfo=getimagesize($datafile);
			if(is_array($imageinfo)){
				$image = true;
				if(function_exists('imagecreatefromstring')) createthumb($datafile, $thumbfile, $thumb_w, $thumb_h);
				else $log .= " gdlib not found";
			}

			$response['key']=	random_str(16);

			$data['path']		= $foldername.rawurlencode($filename);
			$data['key']		= password_hash($response['key'], PASSWORD_DEFAULT);
			$data['upload_ip']	= $ip;
			$data['file_name']	= $_FILES['f']['name'];
			$data['file_type']	= $_FILES['f']['type'];
			$data['file_size']	= $_FILES['f']['size'];

			$response['url']	= $url.$foldername.rawurlencode($filename);
			$response['deleteurl']	= $url.$foldername.rawurlencode($filename) . "?del&key=" . $response['key'];
			$response['thumbnail']	= $url.$foldername.rawurlencode($filename) . "?thumb";
			$response['upload_ts']	= date('c');
			if(is_array($imageinfo)){
				$data['imageinfo'] = $imageinfo;
				$response['imageinfo'] = $imageinfo;
			}


			file_put_contents($jsonfile, json_encode($data), FILE_APPEND);
			$log .= " $url$foldername$filename ({$_FILES['f']['name']})";
		}
		else {
			$log .= " $url$foldername$filename ({$_FILES['f']['name']}) failed";
			$response['error'] = "error";
		}

	}

	if(isset($_POST['type'])) @$type=$_POST['type'];
	if(isset($_GET['type']))  @$type=$_GET['type'];
	switch($type) {
		case "json":
			header("Content-Type: application/json");
			echo json_encode($response);
			return;
		case "text":
			header("Content-Type: text/plain");
			print_r($response);
			return;
		default:
			if(!empty($response['error']))
				echo $response['error'];
			else
				header("Location: ".$response['url']."?uploaded&key={$response['key']}");
			return;
	}
	file_put_contents(".htlog", $log."\n", FILE_APPEND);
	exit();
}

if($param=="config") {
	header("Content-Type: application/json");
	header('Content-Disposition: attachment; filename="ShareX_'.$_SERVER['SERVER_NAME'].'.json"');
	header('Content-Transfer-Encoding: binary');
	$response = array(
		"Name" 		=> $_SERVER['SERVER_NAME'],
		"RequestType"	=> 'POST',
		"RequestURL"	=> $url . 'upload.php?type=json',
		"FileFormName"	=> 'f',
		"ResponseType" => 'Text',
		"URL"		=> '$json:url$',
		"ThumbnailURL"	=> '$json:thumbnail$',
		"DeletionURL"	=> '$json:deleteurl$'
	);
	if(!empty($key)) $response['Arguments'] = array( "key" => "putyourkeyhere" );	// NO!, dont put you key here. This is just default text
	echo json_encode($response);
	exit();
}

if(is_file($datafolder.$param.".json")) {
	$json = json_decode(file_get_contents($datafolder.$param.".json"), true);
	if(isset($_GET['thumb'])) {
		if(is_file($datafolder.$param.".thumb.png")) {
			header('Content-Type: image/png');
			readfile($datafolder.$param.".thumb.png");
			exit();
		}
	}
	if(isset($_GET['del'])) {
		if(is_file($datafolder.$param.".data")) {
			if(password_verify ($_GET['key'] ,$json['key'])) {
				$json['deleted_by'] = $ip;
				$json['deleted_at'] = date('c');
				unlink($datafolder.$param.".data");
				@unlink($datafolder.$param.".thumb.png");
				file_put_contents($datafolder.$param.".json", json_encode($json));
				echo "Deleted";
				exit();
			}
			else {
				echo "Failed to delete: Wrong key";
			}
		}
		echo "Failed to delete.";
		exit();
	}

	if(isset($_GET['uploaded'])) {
		$key = trim($_GET['key']);
		if(password_verify($key ,$json['key'])) {
			buildpage("uploaded.html", array(
				'baseurl' => $url,
				'url' =>       $url.$json['path'],
				'thumbnail' => $url.$json['path']."?thumb",
				'deleteurl' => $url.$json['path']."?del&key=".$key,
				'upload_ts' => @$json['upload_ts'],
				'imagesize' => @$json['imageinfo'][0]."x".$json['imageinfo'][1],
				'file_size' => @$json['file_size'],
				'deletedat' => @$json['deleted_at']
			));
			exit();
		}
	}
	if(isset($_GET['debug'])) {
		header('Content-Type: text/plain');
		print_r($json);
		exit();
	}

	if(is_file($datafolder.$param.".data")) {
		header('Content-Type: ' . $json['file_type']);
		header('Content-Length: ' . $json['file_size']);
		header('Content-Transfer-Encoding: binary');
		header('Content-Disposition: filename="'.$json['file_name'].'"');
		readfile($datafolder.$param.".data");
		exit();
	}
}

if(!empty($param)) {
	header("HTTP/1.0 404 Not Found");
	if(is_file("./404.html"))
		readfile("./404.html");
	else
		echo "404 file not found<br><a href=\"$url\">Click here</a>";
	exit();
}

buildpage("upload.html", array(
	'baseurl' => $url,
));

