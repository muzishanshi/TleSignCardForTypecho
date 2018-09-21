<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 一元打卡
 *
 * @package custom
 */
//设置页头
header("Content-type: text/html; charset=utf-8");
$pluginsname='TleSignCard';
$options = Typecho_Widget::widget('Widget_Options');
$option=$options->plugin($pluginsname);
$plug_url = $options->pluginUrl;
date_default_timezone_set('Asia/Shanghai');
include dirname(__FILE__).'/../../plugins/'.$pluginsname.'/include/email.class.php';
require_once dirname(__FILE__).'/../../plugins/'.$pluginsname.'/include/youzan/YZGetTokenClient.php';
require_once dirname(__FILE__).'/../../plugins/'.$pluginsname.'/include/youzan/YZTokenClient.php';
global $wpdb,$current_user;

if(strpos($this->permalink,'?')){
	$url=substr($this->permalink,0,strpos($this->permalink,'?'));
}else{
	$url=$this->permalink;
}

$page = isset($_GET['page']) ? addslashes(trim($_GET['page'])) : '';
if($page==""){
	$query= $this->db->select()->from('table.signcard_sign')->where('status = ?', 4);
	$rows = $this->db->fetchAll($query);
}else if($page=="notify_url"){
	$db = Typecho_Db::get();
	$prefix = $db->getPrefix();
	/*有赞回调*/
	$json = file_get_contents('php://input'); 
	$data = json_decode($json, true);
	/**
	 * 判断消息是否合法，若合法则返回成功标识
	 */
	$msg = $data['msg'];
	$sign_string = $option->sign_yz_client_id."".$msg."".$option->sign_yz_client_secret;
	$sign = md5($sign_string);
	if($sign != $data['sign']){
		exit();
	}else{
		$result = array("code"=>0,"msg"=>"success") ;
	}
	/**
	 * msg内容经过 urlencode 编码，需进行解码
	 */
	$msg = json_decode(urldecode($msg),true);
	/**
	 * 根据 type 来识别消息事件类型，具体的 type 值以文档为准，此处仅是示例
	 */
	if($data['type'] == "trade_TradePaid"){
		$qrNameArr=explode("|",$msg["qr_info"]["qr_name"]);
		$data = array(
			"orderNumber"=>$data['id'],
			"payChannel"=>$msg["full_order_info"]["order_info"]["pay_type_str"],
			"Money"=>1,
			"qqnum"=>$qrNameArr[1],
			"status"=>1,
			"ip"=>TleSignCard_Plugin::getRealIpForSignCard(),
			"instime"=>date('Y-m-d H:i:s',time())
		);
		$insert = $db->insert('table.signcard_sign')->rows($data);
		$db->query($insert);
	}
	return;
}else if($page=="payqr"){
	$db = Typecho_Db::get();
	$prefix = $db->getPrefix();
	$action = isset($_POST['action']) ? addslashes(trim($_POST['action'])) : '';
	if($action=="submityouzan"){
		$Money = isset($_POST['Money']) ? addslashes(trim($_POST['Money'])) : '';
		$payChannel = isset($_POST['payChannel']) ? addslashes(trim($_POST['payChannel'])) : '';
		$qqnum = isset($_POST['qqnum']) ? addslashes(trim($_POST['qqnum'])) : '';
		$ispay=false;
		
		$query= $db->select()->from('table.signcard_sign')->where('status = ?', 1)->where('qqnum != ?', null)->where('qqnum = ?', $qqnum); 
		$row = $db->fetchRow($query);
		if(count($row)>0){
			$instime=strtotime($row["instime"]);
			$yesterdaystart=strtotime(date("Y-m-d",strtotime("-1 day")).' 00:00:00');
			$yesterdayend=strtotime(date("Y-m-d",strtotime("-1 day")).' 23:59:59');
			$tokaystart=strtotime(date("Y-m-d",strtotime("0 day")).' 06:00:00');
			$tokayend=strtotime(date("Y-m-d",strtotime("0 day")).' 08:00:00');
			$tokaytop=strtotime(date("Y-m-d",strtotime("0 day")).' 00:00:00');
			$tokaybottom=strtotime(date("Y-m-d",strtotime("0 day")).' 23:59:59');
			$time=time();
			if($instime>=$yesterdaystart&&$instime<=$yesterdayend){
				if($time>=$tokaystart&&$time<=$tokayend){
					$update = $db->update('table.signcard_sign')->rows(array('status'=>2))->where('orderNumber=?',$row["orderNumber"]);
					$db->query($update);
					
					TleSignCard_Plugin::sendMailForSignCard($this->user->mail!=""?$this->user->mail:$option->sign_mail,$this->options->title().'网站有用户打卡了','用户QQ：'.$qqnum.'打卡');
					$json=json_encode(array("status"=>"signinok","msg"=>"打卡成功，再接再厉！"));
					echo $json;
				}else if($time>=$tokayend){
					$update = $db->update('table.signcard_sign')->rows(array('status'=>3))->where('orderNumber=?',$row["orderNumber"]);
					$db->query($update);
					$json=json_encode(array("status"=>"signinfail","msg"=>"打卡失败，下次努力！"));
					echo $json;
				}else if($time<=$tokaystart){
					$json=json_encode(array("status"=>"signinnotime","msg"=>"打卡时间未到，再等等吧！"));
					echo $json;
				}
			}else if($instime>=$tokaytop&&$instime<=$tokaybottom){
				$json=json_encode(array("status"=>"signupovertime","msg"=>"你已经报过名了，记得按时间打卡哦！"));
				echo $json;
			}else{
				$update = $db->update('table.signcard_sign')->rows(array('status'=>3))->where('orderNumber=?',$row["orderNumber"]);
				$db->query($update);
				$ispay=true;
			}
		}else{
			$ispay=true;
		}
		if($ispay){
			$token = new YZGetTokenClient( $option->sign_yz_client_id , $option->sign_yz_client_secret );
			$type = $option->sign_shoptype;
			$keys['kdt_id'] = $option->sign_yz_shop_id;
			$keys['redirect_uri'] = $option->sign_yz_redirect_url;
			$token=$token->get_token( $type , $keys );

			$client = new YZTokenClient($token['access_token']);
			$method = 'youzan.pay.qrcode.create';
			$api_version = '3.0.0';
			$my_params = [
				'qr_name' => '一元打卡|'.$qqnum,
				'qr_price' => 100,
				'qr_type' => "QR_TYPE_NOLIMIT",
			];
			$my_files = [];
			$payqrcode=$client->post($method, $api_version, $my_params, $my_files);
			
			$json=json_encode(array("status"=>"signupok","msg"=>"报名成功","qr_code"=>$payqrcode["response"]["qr_code"],"qr_url"=>$payqrcode["response"]["qr_url"]));
			echo $json;
		}
	}
	return;
}
?>
<!DOCTYPE html>
<html>
<head lang="zh">
  <meta charset="UTF-8">
  <title><?php $this->archiveTitle(array('category'=>_t(' %s '),'search'=>_t(' %s '),'tag'=>_t(' %s '),'author'=>_t(' %s ')),'',' - ');?><?php $this->options->title();?></title>
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta property="og:image" content="https://ws3.sinaimg.cn/large/ecabade5ly1fv1bmjcwevj20m80e1wjb.jpg"/>
  <meta name="format-detection" content="telephone=no">
  <meta name="renderer" content="webkit">
  <meta http-equiv="Cache-Control" content="no-siteapp"/>
  <meta name="author" content="同乐儿">
  <meta name="keywords" content="<?php $this->options->title(); ?>">
  <meta name="description" content="<?php $this->options->title(); ?>为全民早起挑战献爱心，签到打卡平分现金。" />
  <link rel="alternate icon" href="https://ws3.sinaimg.cn/large/ecabade5ly1fv1bicm6n9j200s00s744.jpg" />
  <link rel="stylesheet" href="http://cdn.amazeui.org/amazeui/2.7.2/css/amazeui.min.css"/>
  <!--[if lt IE 9]>-->
  <script src="http://libs.baidu.com/jquery/1.11.1/jquery.min.js"></script>
  <!--[endif]-->
  <!--[if (gte IE 9)|!(IE)]><!-->
  <script src="http://lib.sinaapp.com/js/jquery/1.9.1/jquery-1.9.1.min.js"></script>
  <!--<![endif]-->
</head>
<body>
<style>
.page-main{
	background-color:#fff;
	width:960px;
	margin:0px auto 0px auto;
}
@media screen and (max-width: 960px) {
	.page-main {width: 100%;}
}

.get-title {
  font-size: 200%;
  border: 2px solid #000;
  padding: 20px;
  display: inline-block;
}
.get-btn {
  background: #fff;
}
.get{
  min-width: 960px;
}

.detail {
  background: #fff;
}
.detail-h2 {
  text-align: center;
  font-size: 150%;
  margin: 40px 0;
}
</style>
<header>
</header>
<!-- content section -->
<section class="page-main">
	<div data-am-widget="slider" class="am-slider am-slider-a1" data-am-slider='{"directionNav":false}'>
	  <ul class="am-slides">
		<li>
		  <img src="https://ws3.sinaimg.cn/large/ecabade5ly1fv1bmjcwevj20m80e1wjb.jpg" alt="一元打卡">
		</li>
	  </ul>
	</div>
	<div class="am-slider am-slider-a1">
	  <div class="am-slides" style="background: #FFCB00;color: #000;text-align: center;padding: 10px 0;">
		<h1 class="get-title">明日打卡报名</h1>
		<p>
		  明日可平分总额
		</p>
		<p>
		  <span style="font-size: 250%;color:red;">￥10000</span><span>元</span>
		</p>
		<p>
		  <a href="javascript:;" id="signperup" class="am-btn am-btn-sm get-btn">一元打卡</a>
		</p>
		<div class="am-modal am-modal-prompt" tabindex="-1" id="signperup-prompt">
		  <div class="am-modal-dialog">
			<form id="signform" class="am-form" action="" method="post">
				<div class="am-modal-hd">一元打卡</div>
				<small><font color="red">切记要在报名后第二天在到这里打卡哦</font></small>
				<div class="am-g">
					<div class="am-u-md-8 am-u-sm-centered">
					  <fieldset class="am-form-set">
						<input type="hidden" name="Money" value="1"/>
						<input type="hidden" name="payChannel" value="alipay"/>
						<input type="number" maxLength="16" id="qqnum" name="qqnum" placeholder="QQ账号(用于打卡成功打款之用)">
					  </fieldset>
					</div>
				</div>
				<div class="am-modal-footer">
				  <input type="hidden" name="action" value="submitsign" />
				  <span class="am-modal-btn" data-am-modal-cancel>算了/放弃</span>
				  <span class="am-modal-btn" data-am-modal-confirm>报名/打卡</span>
				</div>
			</form>
		  </div>
		</div>
		<script src="https://cdn.bootcss.com/layer/3.1.0/layer.js"></script>
		<script>
		$(function() {
			$('#signperup').on('click', function() {
				$('#signperup-prompt').modal({
					relatedTarget: this,
					onConfirm: function(e) {
						var timer;
						var oldtime = getCookie('signtime');
						var nowtime = Date.parse(new Date()); 
						var sec=(nowtime-oldtime)/1000;
						if(sec<11){
							alert('报名太快了，过'+(11-sec)+'秒重试。');
							timer=setTimeout(function() { 
								clearTimeout(timer);
							},1000) 
							return;
						}
						if($("#qqnum").val()==''&&$("#weixinnum").val()==''&&$("#alipaynum").val()==''){
							alert('至少要填写一个账号');
							return;
						}
						layer.confirm("确定要报名一元打卡吗？", {
							btn: ['我要报名','不报名了']
						}, function(){
							var ii = layer.load(2, {shade:[0.1,'#fff']});
							$.ajax({
								type : "POST",
								url : "<?php echo $url.'?page=payqr';?>",
								data : {"action":"submityouzan","qqnum":$("#qqnum").val()},
								dataType : 'json',
								success : function(data) {
									layer.close(ii);
									if(data.status=="signinok"){
										str="<center>"+data.msg+"</center>";
									}else if(data.status=="signinfail"){
										str="<center>"+data.msg+"</center>";
									}else if(data.status=="signinnotime"){
										str="<center>"+data.msg+"</center>";
									}else if(data.status=="signupovertime"){
										str="<center>"+data.msg+"</center>";
									}else if(data.status=="signupok"){
										str="<center>微信/支付宝扫码支付报名<br /><img src='"+data.qr_code+"'><br /><a href='"+data.qr_url+"' target='_blank'>跳转支付链接</a></center>";
										layer.confirm(str, {
											btn: ['我已报名','放弃报名']
										},function(next){
											layer.confirm("一元打卡<br />报名成功与否取决于是否支付成功<br />如果已经支付，那么切记第二天的06:00:08:00要来打卡哦，不然白报名了！", {
											btn: ['我知道了']
											},function(end){
												window.location.reload();
												layer.close(end);
											});
											layer.close(next);
										});
										return false;
									}
									layer.confirm(str, {
										btn: ['确定']
									},function(index){
										layer.close(index);
									});
								},error:function(data){
									layer.close(ii);
									layer.msg('服务器错误');
									return false;
								}
							});
						});
					},
					onCancel: function(e) {
					}
				});
			});
		});
		</script>
	  </div>
	</div>
	<div class="detail" style="border: 2px solid #FFCB00;">
	  <div class="am-container">
		<h2 class="detail-h2">今日打卡战况</h2>
		<div class="am-g" style="text-align: center;">
		  <p>
			<div>已有<?=count($rows);?>人参与打卡获得了奖励</div>
			<div></div>
			<div>
				<img src="https://ws3.sinaimg.cn/large/ecabade5ly1fv1bqgju7ij20go0c8mz7.jpg" width="50%;" alt="一元打卡">
			</div>
		  </p>
		  <p>平分<span style="font-size: 125%;color:red;">￥10000</span>元</p>
		</div>
	  </div>
	</div>
	<div class="detail" style="border: 2px solid #FFCB00;">
		<div style="text-align: center;">
			<img src="https://ws3.sinaimg.cn/large/ecabade5ly1fv1bqxvoi6j20m806i74n.jpg" width="100%;" alt="一元打卡">
		</div>
		<ul class="am-list am-list-static am-list-border">
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				每日00:00-23:59:00之间支付1元，即可参与全民早起打卡活动，获得次日打卡机会。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				次日早晨06:00-08:00进入打卡页面，且成功打卡后，可平分当日奖金池全部现金，打卡失败则不可参与当日奖金分配。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				周一到周五连续打卡成功的用户，即可获得支付1元用户奖金的5倍。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				每日平分金额于早8点后开始结算，当日9点前到账对应支付账户中。
			</li>
		</ul>
		<div style="text-align: center;">
			活动详细规则
		</div>
		<ul class="am-list am-list-static am-list-border">
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				每日支付1元参与全民打卡，放入全民打卡奖金池。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				每日00:00-23:59:00之间支付1元，可获得次日打卡机会。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				次日早晨06:00-08:00为打卡时间，用户在期间进入打卡页面，且成功打卡，可平分当日奖金池内全部现金。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				未在次日早晨06:00-08:00内进行打卡（例如：已过打卡时间），视为打卡失败，打卡失败不可参与当日奖金分配。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				每日瓜分金额于早8点开始结算，当日9点前到账对应支付账户中。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				用户可通过支付宝支付参与打卡。如出现重复支付或全部参与用户未打卡情况，支付金额将原路返回支付账户中，退款将于次日到账。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				平分奖金金额最终将通过对应支付渠道发放。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				同一QQ号视为同一用户。
			</li>
			<li>
				<i class="am-icon-dot-circle-o am-icon-fw"></i>
				全民打卡是为了倡导用户养成良好的作息习惯，做到用1块钱让你的睡眠更有效率！特制定此计划，提倡公平竞争，参与用户平分奖金池，不收取任何费用，仅供娱乐参与。
			</li>
		</ul>
	</div>
	
</section>
<?php if ($this->user->group=='administrator'){?>
	<?php
	$query= $this->db->select()->from('table.signcard_sign')->order('instime',Typecho_Db::SORT_DESC);
	$rowsAdmin = $this->db->fetchAll($query);

	$page_now = isset($_GET['page_now']) ? intval($_GET['page_now']) : 1;
	if($page_now<1){
		$page_now=1;
	}
	$page_rec=50;
	$totalrec=count($rowsAdmin);
	$page=ceil($totalrec/$page_rec);
	if($page_now>$page){
		$page_now=$page;
	}
	if($page_now<=1){
		$before_page=1;
		if($page>1){
			$after_page=$page_now+1;
		}else{
			$after_page=1;
		}
	}else{
		$before_page=$page_now-1;
		if($page_now<$page){
			$after_page=$page_now+1;
		}else{
			$after_page=$page;
		}
	}
	$i=($page_now-1)*$page_rec<0?0:($page_now-1)*$page_rec;
	$query= $this->db->select()->from('table.signcard_sign')->order('instime',Typecho_Db::SORT_DESC)->offset($i)->limit($page_rec);
	$rowsAdmin = $this->db->fetchAll($query);

	if(isset($_GET['goto'])&&$_GET['goto']=='del'){
		$orderNumber=$_GET['orderNumber'];
		$delete = $this->db->delete('table.signcard_sign')->where('orderNumber = ?', $orderNumber);
		$this->db->query($delete);
		echo "<script>window.location.href='".$url."';</script>";
	}else if(isset($_GET['goto'])&&$_GET['goto']=='ok'){
		$orderNumber=$_GET['orderNumber'];
		$update = $this->db->update('table.signcard_sign')->rows(array('status'=>4))->where('orderNumber=?',$orderNumber);
		$this->db->query($update);
		echo "<script>window.location.href='".$url."';</script>";
	}
	?>
	<div class="wrap nosubsub">
	<h1 class="wp-heading-inline">一元打卡记录管理列表</h1>

	<hr class="wp-header-end">

	<form id="posts-filter" method="get">
		<div class="am-scrollable-horizontal">
		  <table class="am-table am-table-bordered am-table-striped am-text-nowrap">
			<thead>
			<tr>
				<th scope="col" id='orderNumber' class='manage-column column-orderNumber'>
					订单ID
				</th>
				<th scope="col" id='payChannel' class='manage-column column-payChannel'>
					渠道
				</th>
				<th scope="col" id='Money' class='manage-column column-Money'>
					金额
				</th>
				<th scope="col" id='qqnum' class='manage-column column-qqnum'>
					QQ
				</th>
				<th scope="col" id='status' class='manage-column column-status'>
					状态
				</th>
				<th scope="col" id='ip' class='manage-column column-ip'>
					IP
				</th>
				<th scope="col" id='instime' class='manage-column column-instime'>
					时间
				</th>
				<th scope="col" id='operation' class='manage-column column-operation'>
					操作
				</th>
			</tr>
			</thead>
			<tbody id="the-list">
				<?php
				if(count($rowsAdmin)>0){
					foreach($rowsAdmin as $value){
				?>
					<tr>
						<td><?=$value["orderNumber"];?></td>
						<td><?=$value["payChannel"];?></td>
						<td><?=$value["Money"];?></td>
						<td><?=$value["qqnum"];?></td>
						<td>
							<?php
							if($value["status"]==0){
								echo "未报名";
							}else if($value["status"]==1){
								echo '<font color="red"><b>已报名</b></font>';
							}else if($value["status"]==2){
								echo '<font color="green"><b>已打卡</b></font>';
								?>
								<a href="<?=$url;?>?goto=ok&orderNumber=<?=$value["orderNumber"];?>">已付</a>
								<?php
							}else if($value["status"]==3){
								echo '<font color="#88FF88"><b>未打卡</b></font>';
							}else if($value["status"]==4){
								echo '<font color="blue"><b>已打款</b></font>';
							}
							?>
						</td>
						<td><?=$value["ip"];?></td>
						<td>
							<?php
							$instime=strtotime($value["instime"]);
							$yesterdaystart=strtotime(date("Y-m-d",strtotime("-1 day")).' 00:00:00');
							$yesterdayend=strtotime(date("Y-m-d",strtotime("-1 day")).' 23:59:59');
							$tokaytop=strtotime(date("Y-m-d",strtotime("0 day")).' 00:00:00');
							$tokaybottom=strtotime(date("Y-m-d",strtotime("0 day")).' 23:59:59');
							if($instime>=$yesterdaystart&&$instime<=$yesterdayend){
								echo '<font color="#AABBCC"><b>'.$value["instime"].'</b></font>';
							}else if($instime>=$tokaytop&&$instime<=$tokaybottom){
								echo '<font color="EE5566"><b>'.$value["instime"].'</b></font>';
							}else{
								echo $value["instime"];
							}
							?>
						</td>
						<td>
						<a href='javascript:delSignItem("<?=$value["orderNumber"];?>");'>删除</a>
						</td>
					</tr>
				<?php
					}
				}else{
				?>
					<tr class="no-items"><td class="colspanchange" colspan="8">暂无订单</td></tr>
				<?php
				}
				?>
			</tbody>
		  </table>
		</div>

		<div class="tablenav bottom">
			<ul class="am-pagination blog-pagination">
			  <?php if($page_now!=1){?>
				<li class="am-pagination-prev"><a href="<?=$url;?>?page_now=1">首页</a></li>
			  <?php }?>
			  <?php if($page_now>1){?>
				<li class="am-pagination-prev"><a href="<?=$url;?>?page_now=<?=$before_page;?>">&laquo; 上一页</a></li>
			  <?php }?>
			  <?php if($page_now<$page){?>
				<li class="am-pagination-next"><a href="<?=$url;?>?page_now=<?=$after_page;?>">下一页 &raquo;</a></li>
			  <?php }?>
			  <?php if($page_now!=$page){?>
				<li class="am-pagination-next"><a href="<?=$url;?>?page_now=<?=$page;?>">尾页</a></li>
			  <?php }?>
			</ul>
			<br class="clear" />
		</div>

	<div id="ajax-response"></div>
	</form>
	<script>
	function delSignItem(itemid){
		if(confirm("确认要删除该记录吗？")){
			window.location.href='<?=$url;?>?goto=del&orderNumber='+itemid;
		}
	}
	</script>
	</div>
<?php }?>
<script>
/*Cookie操作*/
function clearCookie(){ 
	var keys=document.cookie.match(/[^ =;]+(?=\=)/g); 
	if (keys) { 
		for (var i = keys.length; i--;) 
		document.cookie=keys[i]+'=0;expires=' + new Date( 0).toUTCString() 
	} 
}
function setCookie(name,value,hours){  
    var d = new Date();
    d.setTime(d.getTime() + hours * 3600 * 1000);
    document.cookie = name + '=' + value + '; expires=' + d.toGMTString();
}
function getCookie(name){  
    var arr = document.cookie.split('; ');
    for(var i = 0; i < arr.length; i++){
        var temp = arr[i].split('=');
        if(temp[0] == name){
            return temp[1];
        }
    }
    return '';
}
function removeCookie(name){
    var d = new Date();
    d.setTime(d.getTime() - 10000);
    document.cookie = name + '=1; expires=' + d.toGMTString();
}
</script>
<!--[if lt IE 9]>-->
<script src="http://cdn.staticfile.org/modernizr/2.8.3/modernizr.js"></script>
<script src="http://cdn.amazeui.org/amazeui/2.7.2/js/amazeui.ie8polyfill.min.js"></script>
<!--[endif]-->
<script src="http://cdn.amazeui.org/amazeui/2.7.2/js/amazeui.widgets.helper.min.js" type="text/javascript"></script>
<script src="http://cdn.amazeui.org/amazeui/2.7.2/js/amazeui.min.js" type="text/javascript"></script>
</body>
</html>