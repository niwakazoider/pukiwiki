<?php
/////////////////////////////////////////////////
// PukiWiki - Yet another WikiWikiWeb clone.
//
// $Id: pcomment.inc.php,v 1.23 2003/07/10 03:21:12 arino Exp $
//

/*

*プラグイン pcomment
指定したページにコメントを挿入

*Usage
 #pcomment([ページ名][,表示するコメント数][,オプション])

*パラメータ
-ページ名~
 投稿されたコメントを記録するページの名前
-表示するコメント数~
 過去のコメントを何件表示するか(0で全件)

*オプション
-above~
 コメントをフィールドの前に表示(新しい記事が下)
-below~
 コメントをフィールドの後に表示(新しい記事が上)
-reply~
 2レベルまでのコメントにリプライをつけるradioボタンを表示

*/
// ページ名のデフォルト(%sに$vars['page']が入る)
define('PCMT_PAGE','[[コメント/%s]]');
//
// 表示するコメント数のデフォルト
define('PCMT_NUM_COMMENTS',10);
//
// コメントの名前テキストエリアのカラム数
define('PCMT_COLS_NAME',15);
//
// コメントのテキストエリアのカラム数
define('PCMT_COLS_COMMENT',70);
//
// 挿入する位置 1:末尾 0:先頭
define('PCMT_INSERT_INS',1);
//
//コメントの挿入フォーマット
define('PCMT_FORMAT_NAME','[[%s]]');
define('PCMT_FORMAT_MSG','%s');
define('PCMT_FORMAT_DATE','SIZE(10){%s}');
// \x08は、投稿された文字列中に現れない文字であればなんでもいい。
define('PCMT_FORMAT',"\x08MSG\x08 -- \x08NAME\x08 \x08DATE\x08");
//
// 自動過去ログ化 1ページあたりの件数を指定 0で無効
define('PCMT_AUTO_LOG',0);
// コメントページのタイムスタンプを更新せず、設置ページのタイムスタンプを更新する
define('PCMT_TIMESTAMP',0);

function plugin_pcomment_action()
{
	global $script,$post,$vars;
	
	if ($post['msg'] == '')
	{
		return array();
	}
	
	$retval = pcmt_insert();
	
	if ($retval['collided'])
	{
		$vars['page'] = $post['page'] = $post['refer'];
		return $retval;
	}
	
	header("Location: $script?".rawurlencode($post['refer']));
	exit;
}

function plugin_pcomment_convert()
{
	global $script,$vars;
	global $_pcmt_messages;
	
	//戻り値
	$ret = '';
	
	//パラメータ変換
	$params = array(
		'noname'=>FALSE,
		'nodate'=>FALSE,
		'below' =>FALSE,
		'above' =>FALSE,
		'reply' =>FALSE,
		'_args' =>array()
	);
	array_walk(func_get_args(), 'pcmt_check_arg', &$params);
	
	//文字列を取得
	$page = array_key_exists(0,$params['_args']) ? $params['_args'][0] : '';
	$count = array_key_exists(1,$params['_args']) ? $params['_args'][1] : 0;
	
	if ($page == '')
	{
		$page = sprintf(PCMT_PAGE,strip_bracket($vars['page']));
	}
	
	$_page = get_fullname(strip_bracket($page),$vars['page']);
	if (!is_pagename($_page))
	{
		return sprintf($_pcmt_messages['err_pagename'],htmlspecialchars($_page));
	}
	if ($count == 0 and $count !== '0')
	{
		$count = PCMT_NUM_COMMENTS;
	}
	
	//向きを決定
	$dir = PCMT_INSERT_INS;
	if ($params['above'])
	{
		$dir = 1;
	}
	if ($params['below']) //両方指定されたら下に (^^;
	{
		$dir = 0;
	}
	
	//コメントを取得
	list($comments, $digest) = pcmt_get_comments($_page,$count,$dir,$params['reply']);
	
	//フォームを表示
	if ($params['noname'])
	{
		$title = $_pcmt_messages['msg_comment'];
		$name = '';
	}
	else
	{
		$title = $_pcmt_messages['btn_name'];
		$name = '<input type="text" name="name" size="'.PCMT_COLS_NAME.'" />';
	}
	
	$radio = $params['reply'] ? '<input type="radio" name="reply" value="0" tabindex="0" checked="checked" />' : '';
	$comment = '<input type="text" name="msg" size="'.PCMT_COLS_COMMENT.'" />';
	
	//XSS脆弱性問題 - 外部から来た変数をエスケープ
	$s_page = htmlspecialchars($page);
	$s_refer = htmlspecialchars($vars['page']);
	$s_nodate = htmlspecialchars($params['nodate']);
	$s_count = htmlspecialchars($count);
	
	$form = <<<EOD
  <div>
  <input type="hidden" name="digest" value="$digest" />
  <input type="hidden" name="plugin" value="pcomment" />
  <input type="hidden" name="refer" value="$s_refer" />
  <input type="hidden" name="page" value="$s_page" />
  <input type="hidden" name="nodate" value="$s_nodate" />
  <input type="hidden" name="dir" value="$dir" />
  <input type="hidden" name="count" value="$count" />
  $radio $title $name $comment
  <input type="submit" value="{$_pcmt_messages['btn_comment']}" />
  </div>
EOD;
	if (!is_page($_page))
	{
		$link = make_pagelink($_page);
		$recent = $_pcmt_messages['msg_none'];
	}
	else
	{
		$msg = ($_pcmt_messages['msg_all'] != '') ? $_pcmt_messages['msg_all'] : $_page;
		$link = make_pagelink($_page,$msg);
		$recent = ($count > 0) ? sprintf($_pcmt_messages['msg_recent'],$count) : '';
	}
	
	return $dir ?
		"<div><p>$recent $link</p>\n<form action=\"$script\" method=\"post\">$comments$form</form></div>" :
		"<div><form action=\"$script\" method=\"post\">$form$comments</form>\n<p>$recent $link</p></div>";
}

function pcmt_insert()
{
	global $script,$vars,$post,$now;
	global $_title_updated,$_no_name,$_pcmt_messages;
	
	$page = $post['page'];
	if (!is_pagename($page))
	{
		return array('msg'=>'invalid page name.','body'=>'cannot add comment.','collided'=>TRUE);
	}
	
	check_editable($page, true, true);
	
	$ret = array(
		'msg' => $_title_updated,
		'collided' => FALSE
	);
	
	//コメントフォーマットを適用
	$msg = sprintf(PCMT_FORMAT_MSG,rtrim($post['msg']));
	$name = $post['name'] == '' ? $_no_name : $post['name'];
	$name = ($name == '') ? '' : sprintf(PCMT_FORMAT_NAME,$name);
	$date = ($post['nodate'] == '1') ? '' : sprintf(PCMT_FORMAT_DATE,$now);
	if ($date != '' or $name != '')
	{
		$msg = str_replace("\x08MSG\x08", $msg,  PCMT_FORMAT);
		$msg = str_replace("\x08NAME\x08",$name, $msg);
		$msg = str_replace("\x08DATE\x08",$date, $msg);
	}
	$reply_hash = array_key_exists('reply',$post) ? $post['reply'] : '';
	if ($reply_hash or !is_page($page))
	{
		$msg = preg_replace('/^\-+/','',$msg);
	}
	$msg = rtrim($msg);
	
	if (!is_page($page))
	{
		$postdata = '[['.htmlspecialchars(strip_bracket($post['refer']))."]]\n\n-$msg\n";
	}
	else
	{
		//ページを読み出す
		$postdata = get_source($page);
		
		// 更新の衝突を検出
		if (md5(join('',$postdata)) != $post['digest'])
		{
			$ret['msg'] = $_pcmt_messages['title_collided'];
			$ret['body'] = $_pcmt_messages['msg_collided'];
		}
		
		// 初期値
		$level = 1;
		$pos = 0;
		
		// コメントの開始位置を検索
		while ($pos < count($postdata))
		{
			if (preg_match('/^\-/',$postdata[$pos]))
			{
				break;
			}
			$pos++;
		}
		$start_pos = $pos;
		//リプライ先のコメントを検索
		if ($reply_hash != '')
		{
			while ($pos < count($postdata))
			{
				if (preg_match('/^(\-{1,2})(?!\-)(.*)$/',$postdata[$pos++],$matches)
					and md5($matches[2]) == $reply_hash)
				{
					$level = strlen($matches[1]) + 1; //挿入するレベル
					
					// コメントの末尾を検索
					while ($pos < count($postdata))
					{
						if (preg_match('/^(\-{1,3})(?!\-)/',$postdata[$pos],$matches)
							and strlen($matches[1]) < $level)
						{
							break;
						}
						$pos++;
					}
					break;
				}
			}
		}
		else
		{
			$pos = ($post['dir'] == 0) ? $start_pos : count($postdata);
		}
		
		if ($post['dir'] == '0')
		{
			if ($pos == count($postdata))
			{
				$pos = $start_pos; //先頭
			}
		}
		else
		{
			if ($pos == 0)
			{
				$pos = count($postdata); //末尾
			}
		}
		
		//コメントを挿入
		array_splice($postdata,$pos,0,str_repeat('-',$level)."$msg\n");
		
		// 過去ログ処理
		pcmt_auto_log($page,$post['dir'],$post['count'],$postdata);
		
		$postdata = join('',$postdata);
	}
	page_write($page,$postdata,PCMT_TIMESTAMP);
	if (PCMT_TIMESTAMP)
	{
		// 親ページのタイムスタンプを更新する
		touch(get_filename($post['refer']));
		put_lastmodified();
	}
	return $ret;
}
// 過去ログ処理
function pcmt_auto_log($page,$dir,$count,&$postdata)
{
	if (!PCMT_AUTO_LOG)
	{
		return;
	}
	$keys = array_keys(preg_grep('/(?:^-(?!-).*$)/m',$postdata));
	if (count($keys) < (PCMT_AUTO_LOG + $count))
	{
		return;
	}
	if ($dir) //前からPCMT_AUTO_LOG件
	{
		$old = array_splice($postdata,$keys[0],$keys[PCMT_AUTO_LOG] - $keys[0]);
	}
	else //後ろからPCMT_AUTO_LOG件
	{
		$old = array_splice($postdata,$keys[count($keys) - PCMT_AUTO_LOG]);
	}
	// ページ名を決定
	$i = 0;
	do {
		$i++;
		$_page = "$page/$i";
	} while (is_page($_page));
	
	page_write($_page,"[[$page]]\n\n".join('',$old));
	
	// 繰り返す :)
	pcmt_auto_log($page,$dir,$count,$postdata);
}
//オプションを解析する
function pcmt_check_arg($val, $key, &$params)
{
	if ($val != '')
	{
		$l_val = strtolower($val);
		foreach (array_keys($params) as $key)
		{
			if (strpos($key,$l_val) === 0)
			{
				$params[$key] = TRUE;
				return;
			}
		}
	}
	$params['_args'][] = $val;
}

function pcmt_get_comments($page,$count,$dir,$reply)
{
	global $_msg_pcomment_restrict;
	
	if (!check_readable($page, false, false))
	{
		return array(str_replace('$1',$page,$_msg_pcomment_restrict));
	}
	
	$data = get_source($page);
	
	if (!is_array($data))
	{
		return array('',0);
	}
	
	$digest = md5(join('',$data));
	
	//コメントを指定された件数だけ切り取る
	if ($dir)
	{
		$data = array_reverse($data);
	}
	$num = $cnt = 0;
	$cmts = array();
	foreach ($data as $line)
	{
		if ($count > 0 and $dir and $cnt == $count)
		{
			break;
		}
		if (preg_match('/^(\-{1,2})(?!\-)(.+)$/', $line, $matches))
		{
			if ($count > 0 and strlen($matches[1]) == 1 and ++$cnt > $count)
			{
				break;
			}
			if ($reply)
			{
				++$num;
				$cmts[] = "$matches[1]\x01$num\x02".md5($matches[2])."\x03$matches[2]\n";
				continue;
			}
		}
		$cmts[] = $line;
	}
	$data = $cmts;
	if ($dir)
	{
		$data = array_reverse($data);
	}
	unset($cmts);
	
	//コメントより前のデータを取り除く。
	while (count($data) > 0 and substr($data[0],0,1) != '-')
	{
		array_shift($data);
	}
	
	//html変換
	$comments = convert_html($data);
	unset($data);
	
	//コメントにラジオボタンの印をつける
	if ($reply)
	{
		$comments = preg_replace("/<li>\x01(\d+)\x02(.*)\x03/",'<li class="pcmt"><input class="pcmt" type="radio" name="reply" value="$2" tabindex="$1" />', $comments);
	}
	return array($comments,$digest);
}
?>
