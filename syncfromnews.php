<?php
define("IN_MYBB", 1);
define('THIS_SCRIPT', 'fetchnews.php');
define("IN_SYNCOM", 1);

$basepath = dirname($_SERVER["SCRIPT_FILENAME"]);

require_once $basepath."/../global.php";

require MYBB_ROOT.'/syncom/config.php';

require_once 'Net/NNTP/Client.php';
require_once 'Mail/RFC822.php';
require_once 'Mail.php';
require_once 'Mail/mime.php';

require_once "convertpost.php";

require_once "mybbapi.php";

function fetcharticles($nntp, $newsgroup, $start, $end = -1)
{
	global $syncom;

	//$nntp = new Net_NNTP_Client();
	//$ret = $nntp->connect($syncom['newsserver'], false, '119', 3);
	//if(PEAR::isError($ret)) {
	//	echo $ret->message."\r\n".$ret->userinfo."\r\n";
	//	return(false);
	//}

	//if ($syncom['user'] != '') {
	//	$ret = $nntp->authenticate($syncom['user'], $syncom['password']);
	//	if(PEAR::isError($ret)) {
	//		echo $ret->message."\r\n".$ret->userinfo."\r\n";
	//		return(false);
	//	}
	//}

	$ret = $nntp->selectGroup($newsgroup);
	if(PEAR::isError($ret)) {
		echo $ret->message."\r\n".$ret->userinfo."\r\n";
		return(false);
	}

	$first = $nntp->first();

	if ($start > $first)
		$first = $start;

	$last = $nntp->last();

	if (($end < $last) and ($end != -1))
		$last = $end;

	for ($i = $first; $i <= $last; $i++) {
		$article = $nntp->getArticle($i, true);
		if(!PEAR::isError($article)) {
			file_put_contents($syncom['incoming-spool'].'/'.$newsgroup.'-'.substr('00000000'.$i, -8), 
					serialize(array('newsgroup' => $newsgroup, 'number' => $i, 'article' => $article)));
		}

	}

	return($last);
}

function processarticle($api, $fid, $article, $articlenumber)
{
	global $db, $syncom;

	//echo $fid."\r\n";
	// Zerlegen der Nachricht
	$struct = convertpost($article);

	// x-no-archive wird nicht uebertragen
	if (strtolower($struct['x-no-archive']) == 'yes') {
		echo "X-No-Archive\r\n";
		//$struct['subject'] = '(X-No-Archive)';
		$struct['body'] = '(X-No-Archive)';
		$struct['from']['mailbox'] = 'nobody';
		$struct['from']['host'] = 'nowhere.tld';
		$struct['from']['personal'] = 'nobody';
		//return(true);
	}

	if (strtolower(substr($struct['body'],0,17)) == 'x-no-archive: yes') {
		echo "X-No-Archive\r\n";
		//$struct['subject'] = '(X-No-Archive)';
		$struct['body'] = '(X-No-Archive)';
		$struct['from']['mailbox'] = 'nobody';
		$struct['from']['host'] = 'nowhere.tld';
		$struct['from']['personal'] = 'nobody';
		//return(true);
	}

	// Erkennen eines Supersedes
	$supersede = (strtolower($struct['supersedes']) != '');

	if ($supersede) {
		$post = $api->getidbymessageid($struct['supersedes'], $fid);
		if ($post['pid'] == 0)
			$supersede = false;
	}

	// wurde die Nachricht bereits gepostet?

	//echo $struct['message-id']."\r\n";
	$post = $api->getidbymessageid($struct['message-id'], $fid);

	// Pruefung, ob der Artikel bereits ohne Nummer existiert
	if (($post['syncom_articlenumber'] != $articlenumber) and ($post['pid'] != 0)) {
		echo "Insert articlenumber\r\n";
		$db->update_query("posts", array('syncom_articlenumber'=>$articlenumber, 'visible'=>1), "pid=".$db->escape_string($post['pid']));

		if (!$post['visible']) {
			echo "Publish thread, update counter\r\n";
			$query = $db->simple_select("threads", "replies, unapprovedposts", "tid=".$db->escape_string($post['tid']), array('limit' => 1));
			$thread = $db->fetch_array($query);
			$replies = $thread['replies'];
			$unapprovedposts = $thread['unapprovedposts'];
			if ($unapprovedposts > 0) {
				$replies++;
				$unapprovedposts--;
			}
			$db->update_query("threads", array('visible'=>1, 'replies'=>$replies, 'unapprovedposts'=>$unapprovedposts), "tid=".$db->escape_string($post['tid']));
		}
	}

	// wenn ja und kein Supersede => nicht posten
	if (($post['pid'] != 0) and !$supersede) {
		echo "already posted\r\n";
		return(true);
	}

	if (!$supersede) {
		$post = array('tid'=>0, 'pid'=>0, 'uid'=>0);;

		// Anhand der References den letzten Artikel finden
		foreach ($struct['references'] as $references) {
			$postref = $api->getidbymessageid($references, $fid);

			if ($postref['tid'] != 0)
				$post = $postref;
		}
	}

	// Und dann schauen, ob es den gleichen Betreff innerhalb von X Tagen gab

        if ($post['pid'] == 0)
		$post = $api->getidbysubject($struct, $fid);

	// Wenn immer noch kein Bezug gefunden wird, wird das "re:" entfernt
	if ($post['pid'] == 0)
		if (strtolower(substr($struct['subject'],0,3)) == 're:') 
			$struct['subject'] = ltrim(substr($struct['subject'], 3));
			//die($struct['message-id']);

	$user = $struct['from']['personal'];

	if ($user == '')
		$user = $struct['from']['mailbox'];

	$email = $struct['from']['mailbox'].'@'.$struct['from']['host'];

	$sender = $struct['sender']['mailbox'].'@'.$struct['sender']['host'];

	if ($sender == $syncom['syncuser'])
		$sender = '';

	$userdata = $api->getuserbymail($email, $sender);

	if ($supersede) {
		$old = $api->getidbymessageid($struct['supersedes'], $fid);

		return($api->edit($old['tid'], $old['pid'], $old['replyto'], $struct['subject'], $struct['body'], 
			$userdata['uid'], $user, $struct['date'], $struct['message-id'], $articlenumber, $email));

	} else {
		return($api->post($fid, $post['tid'], $post['pid'], $struct['subject'], $struct['body'], 
			$userdata['uid'], $user, $struct['date'], $struct['message-id'], $articlenumber, $email));
	}
}

function processarticles()
{
	global $syncom;

	$api = new mybbapi;

	$dir = scandir($syncom['incoming-spool'].'/');

	foreach ($dir as $spoolfile) {
		$file = $syncom['incoming-spool'].'/'.$spoolfile;
		if (!is_dir($file) and (file_exists($file))) {
			$message = unserialize(file_get_contents($file));

			$fid = $api->getforumid($message['newsgroup']);

			echo $fid." - ".$file."\r\n";

			if (($fid == 0) or processarticle($api, $fid, $message['article'], $message['number']))
				@unlink($file);
			else
				rename($file, $syncom['incoming-spool'].'/error/'.$spoolfile);
		}
	}
}

function fetchgroups()
{
	global $db, $syncom;

	$query = $db->simple_select("forums", "syncom_newsgroup", "syncom_newsgroup!=''");

	$newsgroups = array();
	while ($forum = $db->fetch_array($query))
		$newsgroups[] = $forum['syncom_newsgroup'];

	$nntp = new Net_NNTP_Client();
	$ret = $nntp->connect($syncom['newsserver'], false, '119', 3);
	if(PEAR::isError($ret)) {
		echo $ret->message."\r\n".$ret->userinfo."\r\n";
		return(false);
	}

	if ($syncom['user'] != '') {
		$ret = $nntp->authenticate($syncom['user'], $syncom['password']);
		if(PEAR::isError($ret)) {
			echo $ret->message."\r\n".$ret->userinfo."\r\n";
			return(false);
		}
	}

	foreach ($newsgroups as $newsgroup) {

		if (file_exists($syncom['newsrc']))
			$newsrc = unserialize(file_get_contents($syncom['newsrc']));

		$ret = fetcharticles($nntp, $newsgroup, $newsrc[$newsgroup] + 1);
		if(PEAR::isError($ret)) {
			echo $ret->message."\r\n".$ret->userinfo."\r\n";
		} else {
			$newsrc[$newsgroup] = $ret;
			file_put_contents($syncom['newsrc'], serialize($newsrc));
		}
		processarticles();
	}
}

// Newsgroups -> Eingangsspool
fetchgroups();

// Eingangsspool -> Forum
processarticles();

?>