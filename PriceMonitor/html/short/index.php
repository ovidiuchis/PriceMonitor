<?php
ini_set('display_errors', '1');
error_reporting(-1);
date_default_timezone_set('UTC');

define('TIMESTAMP', microtime(true));
require_once("db.cfg.php");


class DB {
	static $instance = null;
	static public function getInstance() {
		if (self::$instance==null) {
			self::$instance = new self();
			self::$instance->connect();
		}
		
		return self::$instance;
	}
	
	
	protected $db = null;
	
	public function connect() {
		try {
			$this->db = new PDO(DB_DSN, DB_USER, DB_PASS);
			$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		} 
		catch (PDOException $e) {
			echo 'DB Connection failed';
		}
		
		return $this;
	}
	
	public function getAssoc($query) {
		#return $this->db->query($query, PDO::FETCH_ASSOC);
		$results = Array();
		foreach($this->db->query($query, PDO::FETCH_ASSOC) as $res) {
			$results[]= $res;
		}
		
		return $results;
	}
	public function getRow($query) {
		$result = $this->db->query($query, PDO::FETCH_ASSOC)->fetch();
		return $result;
	}
	
	public function getOne($query) {
		$result = $this->db->query($query, PDO::FETCH_ASSOC)->fetch();
		return reset($result);
	}
	
	public function getCol($query) {
		$result = $this->db->query($query)->fetchAll(PDO::FETCH_COLUMN);
		return $result;
	}
}

class Products {
	public function getAll() {
		$results = DB::getInstance()->getAssoc("SELECT `id` FROM `products` GROUP BY `id` ORDER BY `name` ASC");
		return $results;
	}
	public function getLastRunTime() {
		$lastRow = DB::getInstance()->getRow("SELECT MAX(`lastSeen`), `run_id` FROM `products`");
		$lastRunTime = DB::getInstance()->getOne("SELECT MIN(`lastSeen`) FROM `products` WHERE `run_id`='{$lastRow['run_id']}'");
		return $lastRunTime;
	}
	public function getAvailable() {
		$results = DB::getInstance()->getAssoc("SELECT `id`, `spider` FROM `products` WHERE `lastSeen`>='{$this->getLastRunTime()}' GROUP BY `id` ORDER BY `name` ASC");
		return $results;
	}
}

class Product {
	protected $id = null;
	protected $spider = null;
	protected $data = null;
	protected $tags = null;
	
	protected $date_tz = null;
	
	public function __construct($id, $spider)
	{
		$this->id = $id;
		$this->spider = $spider;
		
		$this->date_tz  = new DateTimeZone('Europe/Bucharest');
	}
	
	function getHistory() {
		$history = DB::getInstance()->getAssoc("SELECT * FROM `products` WHERE `id`='{$this->id}' AND `spider`='{$this->spider}' ORDER BY `lastSeen` DESC");
		foreach($history as $k=>$hist) {
			$hist['addTime_obj'] = $this->createDate($hist['addTime']);
			$hist['lastSeen_obj'] = $this->createDate($hist['lastSeen']);
		
			$hist['isAvailable'] = $this->isAvailable() || ($this->getAge($hist['lastSeen_obj'])<(60*60*24));
			
			$hist['addTime_str'] = $hist['addTime_obj']->format("Y-m-d H:i:s");
			$hist['lastSeen_str'] = $hist['lastSeen_obj']->format("Y-m-d H:i:s");
			
			$history[$k] = $hist;
		}
		return $history;
	}
	
	function isAvailable()
	{
		return (bool)$this->getData()['isAvailable'];
	}
	
	function getData() {
		if ($this->data!==null) {
			return $this->data;
		}
		$this->data = DB::getInstance()->getRow("SELECT * FROM `products` WHERE `id`='{$this->id}' AND `spider`='{$this->spider}'ORDER BY `lastSeen` DESC LIMIT 1");
		
		$this->data['addTime_obj'] = $this->createDate($this->data['addTime']);
		$this->data['lastSeen_obj'] = $this->createDate($this->data['lastSeen']);
		
		$this->data['isAvailable'] = ($this->getAge($this->data['lastSeen_obj'])<(60*60*24));
		
		$this->data['addTime_str'] = $this->data['addTime_obj']->format("Y-m-d H:i:s");
		$this->data['lastSeen_str'] = $this->data['addTime_obj']->format("Y-m-d H:i:s");
		
		$this->data['tags'] = $this->getTags();
		$this->data['tagsData'] = $this->extractTagsData($this->data['tags']);
		
		$this->data['tagsData']['unitPrice'] = null;
		if ($this->data['tagsData']['quantity']) {
			$this->data['tagsData']['unitPrice'] = $this->data['price']/$this->data['tagsData']['quantity'];
		}
		
		return $this->data;
	}
	
	function getTags()
	{
		if ($this->tags!==null) {
			return $this->tags;
		}
		$this->tags = (array)DB::getInstance()->getCol("SELECT `tag` FROM `tags` WHERE `URL`='{$this->getData()['URL']}' AND `spider`='{$this->spider}' ORDER BY `tag` ASC");
		
		return $this->tags;
	}
	
	function extractTagsData($tags)
	{
		$ret = Array(
			'product' => '',
			'quantity' => 0,
		);
		foreach($tags as $tag) {
			if (preg_match("/^prod:(?P<product>.+)/", $tag, $m)) {
				$ret['product'] = $m['product'];
			}
			elseif (preg_match("/^q:(?P<quantity>[0-9]+)/", $tag, $m)) {
				$ret['quantity'] = $m['quantity'];
			}
			else {
				// var_dump($tag);
			}
		}
		return $ret;
	}
	
	protected function createDate($dateStr) {
		$dtObj = DateTime::createFromFormat("Y-m-d H:i:s", $dateStr, new DateTimeZone('UTC'));
		$dtObj->setTimeZone($this->date_tz);
		
		return $dtObj;
	}

	public function getAge($date)
	{
		return (TIMESTAMP - $date->getTimestamp());
	}
}


/*
$products = new Products();
foreach ($products->getAvailable() as $rawProd) {
	$prod = new Product($rawProd['id'], $rawProd['spider']);
	var_dump($prod->getHistory());
}
*/



?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, user-scalable=yes">
	
	<title>price monitor - availabile info</title>
	<script>document.write('<base href="' + document.location + '" />');</script>
	
	<!-- CSS -->
	<link rel="stylesheet" type="text/css" href="css/index.css" media="only screen and (min-device-width: 100px)" />
</head>

<body>
	<div class='stats'>
		<div>
			<span class='label'>recorded items</span>
			<span class='value'>-</span>
		</div>
	</div>
	
	<div class='history'>
		<table class="products">
			<?php 
				$priceDiffs = Array(
					'diff-500'=>0.500, 
					'diff-200'=>0.200, 
					'diff-150'=>0.150, 
					'diff-100'=>0.100, 
					'diff-050'=>0.050, 
					'diff-020'=>0.020, 
					'diff-010'=>0.010,
					'diff-005'=>0.005,
					'diff-001'=>0.001,
				);
				
				$products = new Products();
				$availableProducts = $products->getAvailable();
				usort($availableProducts, function($a, $b) {
					$ret = 0;
					
					$ap = new Product($a['id'], $a['spider']);
					$bp = new Product($b['id'], $b['spider']);
					
					$atd = $ap->getData()['tagsData'];
					$btd = $bp->getData()['tagsData'];
					
					if (!$ret) {
						#$ret = strcmp(implode(", ", $ap->getData()['tags']), implode(", ", $bp->getData()['tags']));
						$as = sprintf("%-32s.%06d", $atd['product'], $atd['quantity']);
						$bs = sprintf("%-32s.%06d", $btd['product'], $btd['quantity']);
						$ret = strcmp($as, $bs);
					}
					if(!$ret) {
						$ret = -1*($ap->isAvailable() - $bp->isAvailable());
					}
					
					if(!$ret && $atd['unitPrice'] && $btd['unitPrice']) {
						$ret = 1*(int)1000*($atd['unitPrice'] - $btd['unitPrice']);
					}
					
					if(!$ret) {
						$ret = (int)($ap->getData()['price'] - $bp->getData()['price']);
					}
					
					if(!$ret) {
						$ret = strcmp($ap->getData()['name'], $bp->getData()['name']);
					}
					
					return $ret;
				});

				foreach ($availableProducts as $rawProd) {
					$prod = new Product($rawProd['id'], $rawProd['spider']);
					$data = $prod->getData();
					
					$history = $prod->getHistory();
					foreach ($history as $k=>$hist) {
						if($hist['run_id']==$data['run_id']) {
							unset($history[$k]);
						}
					}
					
					/* compute the class relative to the first item in history */
					$trClass = Array();
					$fhist = reset($history);
					$sign = 0;
					$adiff = 0;
					if ($fhist) {
						if ($fhist['price']>$data['price']) {
							$trClass[]= "price-lower";
							$sign = -1;
						}
						elseif ($fhist['price']<$data['price']) {
							$trClass[]= "price-higher";
							$sign = +1;
						}
						
						$adiff = min($fhist['price'], $data['price'])/max($fhist['price'], $data['price']);
						foreach ($priceDiffs as $cls=>$diff) {
							$diffl = (1-$diff);
							
							if ($adiff<$diffl) {
								$trClass[]= $cls;
								break;
							}
						}
					}
					
					$trClass[] = ($data['isAvailable']?'available':'unavailable');
					
					$tagsClass = Array();
					$tagsHtml = Array();
					/*
					foreach ($data['tags'] as $tag) {
						$cls = preg_replace("/[^a-zA-Z0-9_-]/", "_", $tag);
						$tagsClass[] = "tag-{$cls}";
						$tagsHtml[] = sprintf("<span class='tag-%s'>%s</span>", $cls, $tag);
					}
					*/
					$tag = 'product';
					$tagsHtml[] = sprintf("<span class='tag-%s'>%s</span>", preg_replace("/[^a-zA-Z0-9_-]/", "_", $data['tagsData'][$tag]), $data['tagsData'][$tag]);
					
					$tag = 'quantity';
					$tagsHtml[] = sprintf("<span>%s</span>", $data['tagsData'][$tag]);
					
			?>
				<tbody>
					<tr class="current <?=implode(" ", $trClass)?>">
						<td class="name" rowspan="<?=count($history)+1?>">
							<div class='tags <?=implode(" ", $tagsClass)?>'><?=implode("", $tagsHtml)?></div>
							<div class='label'><?=$data['name']?></div>
						</td>
						<td class="price">
							<div class='total'><span class='value'><?=number_format($data['price'], 2)?></span> <span class='currency'><?=$data['currency']?><span></div>
							<?php if($data['tagsData'] && $data['tagsData']['unitPrice']) { ?>
								<div class='unitPrice'><span class='value'><?=number_format($data['tagsData']['unitPrice'], 3)?></span> <span class='currency'><?=$data['currency']?><span> / unit</div>
							<?php } ?>
						</td>
						<td class="priceDetails"><?php
						if ($sign) {
							printf("%s%s%%", ($sign>0?'+':'-'), number_format((1-$adiff)*100, 3));
						}
						?></td>
						<td class="URL" rowspan="<?=count($history)+1?>"><a href="<?=$data['URL']?>" target="_blank"><?=$data['spider']?></a></td>
						<td class="addTime"><?=$data['addTime_str']?></td>
						<td class="lastSeen"><?=$data['lastSeen_str']?></td>
					</tr>
					
					<?php 
						$lhist = $data;
						$hidx = -1;
						foreach ($history as $hist) {
							$hidx++;
							$trClass = Array();
							$sign = 0;
							if ($lhist['price']>$hist['price']) {
								$trClass[]= "price-lower";
								$sign = -1;
							}
							elseif ($lhist['price']<$hist['price']) {
								$trClass[]= "price-higher";
								$sign = +1;
							}
							
							$adiff = min($lhist['price'], $hist['price'])/max($lhist['price'], $hist['price']);
							foreach ($priceDiffs as $cls=>$diff) {
								$diffl = (1-$diff);
								
								if ($adiff<$diffl) {
									$trClass[]= $cls;
									break;
								}
							}
							
							$trClass[] = ($hist['isAvailable']?'available':'unavailable');
							
							?>
							<tr class="history <?=implode(" ", $trClass)?>">
								<td class="price"><span class='value'><?=number_format($hist['price'], 2)?></span> <span class='currency'><?=$hist['currency']?><span></td>
								<td class="priceDetails"><?php
								if ($sign && $hidx>0) {
									printf("%s%s%%", ($sign>0?'+':'-'), number_format((1-$adiff)*100, 3));
								}
								?></td>
								<td class="addTime"><?=$hist['addTime']?></td>
								<td class="lastSeen"><?=$hist['lastSeen']?></td>
							</tr>
							<?php
						}
					?>
				</tbody>
			<?php 
				}
			?>
		</table>
	</div>
	
	<!--
	<script src="//cdnjs.cloudflare.com/ajax/libs/moment.js/2.9.0/moment.min.js"></script>
	<script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>
	<script src="//cdnjs.cloudflare.com/ajax/libs/underscore.js/1.8.2/underscore-min.js"></script>
	
	<script type="text/javascript" src= "lib/index.js"></script>
	-->
</body>
</html>
