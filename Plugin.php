<?php
/**
 * TleSignCard一元打卡插件是一款为激励用户早起挑战、调整作息的Typecho插件。
 * @package TleSignCard For Typecho
 * @author 二呆
 * @version 1.0.2
 * @link http://www.tongleer.com/
 * @date 2018-09-21
 */
class TleSignCard_Plugin implements Typecho_Plugin_Interface
{
    // 激活插件
    public static function activate(){
		$db = Typecho_Db::get();
		$prefix = $db->getPrefix();
		//初始化一元打卡数据表、模板文件、独立页面
		self::moduleSignCard($db);
        return _t('插件已经激活，需先配置信息！');
    }

    // 禁用插件
    public static function deactivate(){
		//删除页面模板
		$db = Typecho_Db::get();
		$queryTheme= $db->select('value')->from('table.options')->where('name = ?', 'theme'); 
		$rowTheme = $db->fetchRow($queryTheme);
		@unlink(dirname(__FILE__).'/../../themes/'.$rowTheme['value'].'/page_signcard_sign.php');
        return _t('插件已被禁用');
    }

    // 插件配置面板
    public static function config(Typecho_Widget_Helper_Form $form){
		//版本检查
		$version=file_get_contents('http://api.tongleer.com/interface/TleSignCard.php?action=update&version=2');
		$headDiv=new Typecho_Widget_Helper_Layout();
		$headDiv->html('版本检查：'.$version);
		$headDiv->render();
		
		$db = Typecho_Db::get();
		$prefix = $db->getPrefix();
		
		$sign_yz_client_id = new Typecho_Widget_Helper_Form_Element_Text('sign_yz_client_id', null, '', _t('有赞client_id'), _t('在<a href="https://www.youzanyun.com/" target="_blank">有赞云官网</a>授权绑定有赞微小店APP的店铺后注册的client_id'));
        $form->addInput($sign_yz_client_id);
		$sign_yz_client_secret = new Typecho_Widget_Helper_Form_Element_Text('sign_yz_client_secret', null, '', _t('有赞client_secret'), _t('在<a href="https://www.youzanyun.com/" target="_blank">有赞云官网</a>授权绑定有赞微小店APP的店铺后注册的client_secret'));
        $form->addInput($sign_yz_client_secret);
		$sign_yz_shop_id = new Typecho_Widget_Helper_Form_Element_Text('sign_yz_shop_id', null, '', _t('有赞授权店铺id'), _t('在<a href="https://www.youzanyun.com/" target="_blank">有赞云官网</a>授权绑定有赞微小店APP的店铺后注册的授权店铺id'));
        $form->addInput($sign_yz_shop_id);
		$sign_yz_redirect_url = new Typecho_Widget_Helper_Form_Element_Text('sign_yz_redirect_url', null, '', _t('有赞消息推送网址'), _t('在<a href="https://www.youzanyun.com/" target="_blank">有赞云官网</a>授权绑定有赞微小店APP的店铺后注册的消息推送网址'));
        $form->addInput($sign_yz_redirect_url);
		$sign_shoptype = new Typecho_Widget_Helper_Form_Element_Radio('sign_shoptype', array(
            'oauth'=>_t('工具型'),
            'self'=>_t('自用型')
        ), 'self', _t('自用型'), _t("店铺应用种类"));
        $form->addInput($sign_shoptype->addRule('enum', _t(''), array('oauth', 'self')));
		
		$sign_mail = new Typecho_Widget_Helper_Form_Element_Text('sign_mail', null, '', _t('打卡接收邮箱'));
        $form->addInput($sign_mail->addRule('required', _t('打卡接收邮箱不能为空！')));
		
		$sign_mailserver = new Typecho_Widget_Helper_Form_Element_Text('sign_mailserver', null, '', _t('smtp服务器地址'));
        $form->addInput($sign_mailserver->addRule('required', _t('smtp服务器地址不能为空！')));
		
		$sign_mailport = new Typecho_Widget_Helper_Form_Element_Text('sign_mailport', null, '', _t('smtp服务器端口'));
        $form->addInput($sign_mailport->addRule('required', _t('smtp服务器端口不能为空！')));
		
		$sign_mailuser = new Typecho_Widget_Helper_Form_Element_Text('sign_mailuser', null, '', _t('smtp邮箱用户名'));
        $form->addInput($sign_mailuser->addRule('required', _t('smtp邮箱用户名不能为空！')));
		
		$sign_mailpass = new Typecho_Widget_Helper_Form_Element_Text('sign_mailpass', null, '', _t('smtp邮箱密码'));
        $form->addInput($sign_mailpass->addRule('required', _t('smtp邮箱密码不能为空！')));
    }
	
	/*初始化一元打卡数据表、模板文件、独立页面*/
	public static function moduleSignCard($db){
		//创建一元打卡所用数据表
		self::createTableSignCard($db);
		//判断目录权限，并将插件文件写入主题目录
		self::funWriteThemePage($db,'page_signcard_sign.php');
		//如果数据表没有添加页面就插入
		self::funWriteDataPage($db,'一元打卡','signcard_sign','page_signcard_sign.php');
	}
	
	/*创建一元打卡所用数据表*/
	public static function createTableSignCard($db){
		$prefix = $db->getPrefix();
		//$db->query('DROP TABLE IF EXISTS '.$prefix.'multi_baidusubmit');
		$db->query('CREATE TABLE IF NOT EXISTS `'.$prefix.'signcard_sign` (
		  `orderNumber` varchar(125) COLLATE utf8_bin NOT NULL,
		  `payChannel` varchar(255) COLLATE utf8_bin DEFAULT NULL,
		  `Money` int(11) DEFAULT NULL,
		  `qqnum` varchar(16) COLLATE utf8_bin DEFAULT NULL,
		  `status` smallint(2) DEFAULT "0" COMMENT "订单状态：0、未报名；1、已报名；2、已打卡；3、未打卡；4、已打款。",
		  `ip` varchar(255) COLLATE utf8_bin DEFAULT NULL,
		  `instime` datetime DEFAULT NULL,
		  PRIMARY KEY (`orderNumber`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_bin;');
	}
	
	/*公共方法：将页面写入数据库*/
	public static function funWriteDataPage($db,$title,$slug,$template){
		$query= $db->select('slug')->from('table.contents')->where('template = ?', $template); 
		$row = $db->fetchRow($query);
		if(count($row)==0){
			$contents = array(
				'title'      =>  $title,
				'slug'      =>  $slug,
				'created'   =>  Typecho_Date::time(),
				'text'=>  '<!--markdown-->',
				'password'  =>  '',
				'authorId'     =>  Typecho_Cookie::get('__typecho_uid'),
				'template'     =>  $template,
				'type'     =>  'page',
				'status'     =>  'hidden',
			);
			$insert = $db->insert('table.contents')->rows($contents);
			$insertId = $db->query($insert);
			$slug=$contents['slug'];
		}else{
			$slug=$row['slug'];
		}
	}
	/*公共方法：将页面写入主题目录*/
	public static function funWriteThemePage($db,$filename){
		$queryTheme= $db->select('value')->from('table.options')->where('name = ?', 'theme'); 
		$rowTheme = $db->fetchRow($queryTheme);
		if(!is_writable(dirname(__FILE__).'/../../themes/'.$rowTheme['value'])){
			Typecho_Widget::widget('Widget_Notice')->set(_t('主题目录不可写，请更改目录权限。'.__TYPECHO_THEME_DIR__.'/'.$rowTheme['value']), 'success');
		}
		if(!file_exists(dirname(__FILE__).'/../../themes/'.$rowTheme['value']."/".$filename)){
			$regfile = fopen(dirname(__FILE__)."/page/".$filename, "r") or die("不能读取".$filename."文件");
			$regtext=fread($regfile,filesize(dirname(__FILE__)."/page/".$filename));
			fclose($regfile);
			$regpage = fopen(dirname(__FILE__).'/../../themes/'.$rowTheme['value']."/".$filename, "w") or die("不能写入".$filename."文件");
			fwrite($regpage, $regtext);
			fclose($regpage);
		}
	}
	
	public static function sendMailForSignCard($email,$title,$content){
		$option=self::getConfig();
		$smtpserverport =$option->sign_mailport;//SMTP服务器端口:465
		$smtpserver = $option->sign_mailserver;//SMTP服务器:ssl://smtp.qq.com
		$smtpusermail = $option->sign_mailuser;//SMTP服务器的用户邮箱
		$smtpemailto = $email;//发送给谁
		$smtpuser = $option->sign_mailuser;//SMTP服务器的用户帐号
		$smtppass = $option->sign_mailpass;//SMTP服务器的用户密码
		$mailtitle = $title;//邮件主题
		$mailcontent = $content;//邮件内容
		$mailtype = "HTML";//邮件格式（HTML/TXT）,TXT为文本邮件
		//************************ 配置信息 ****************************
		$smtp = new smtp($smtpserver,$smtpserverport,true,$smtpuser,$smtppass);//这里面的一个true是表示使用身份验证,否则不使用身份验证.
		$smtp->debug = false;//是否显示发送的调试信息
		$state = $smtp->sendmail($smtpemailto, $smtpusermail, $mailtitle, $mailcontent, $mailtype);
		return $state;
	}
	
	public static function getRealIpForSignCard(){
	   $ip = $_SERVER['REMOTE_ADDR'];
		if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && preg_match_all('#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#s', $_SERVER['HTTP_X_FORWARDED_FOR'], $matches)) {
			foreach ($matches[0] AS $xip) {
				if (!preg_match('#^(10|172\.16|192\.168)\.#', $xip)) {
					$ip = $xip;
					break;
				}
			}
		} elseif (isset($_SERVER['HTTP_CLIENT_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_CLIENT_IP'])) {
			$ip = $_SERVER['HTTP_CLIENT_IP'];
		} elseif (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_CF_CONNECTING_IP'])) {
			$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
		} elseif (isset($_SERVER['HTTP_X_REAL_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_X_REAL_IP'])) {
			$ip = $_SERVER['HTTP_X_REAL_IP'];
		}
		return $ip;
	}
	
    // 个人用户配置面板
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    // 获得插件配置信息
    public static function getConfig(){
        return Typecho_Widget::widget('Widget_Options')->plugin('TleSignCard');
    }
}