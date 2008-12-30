<?
// The source code packaged with this file is Free Software, Copyright (C) 2008 by
// Ricardo Galli <gallir at uib dot es>.
// It's licensed under the AFFERO GENERAL PUBLIC LICENSE unless stated otherwise.
// You can get copies of the licenses here:
//      http://www.affero.org/oagpl.html
// AFFERO GENERAL PUBLIC LICENSE is also included in the file called "COPYING".

class BasicThumb {
	public $x = 0;
	public $y = 0;
	public $image = false;
	public $referer = false;
	public $type = 'external';
	public $url = false;
	public $checked = false;
	protected $parsed_url = false;
	protected $parsed_referer = false;


	function __construct($url='', $referer=false) {
		$url = $this->clean_url($url);
		if ($referer) $this->parsed_referer = parse_url($referer);
		$this->url = build_full_url($url, $referer);
		$this->parsed_url = parse_url($this->url);
		$this->referer = $referer;
	}

	function clean_url($str) {
		return clean_input_url(preg_replace('/ /', '%20', $str));
	}

	function scale($size=100) {
		if (!$this->image && ! $this->checked) {
			$this->get();
		}
		if (!$this->image) return false;
		if ($this->x > $this->y) {
			$percent = $size/$this->x;
		} else {
			$percent = $size/$this->y;
		}
		$min = min($this->x*$percent, $this->y*$percent);
		if ($min < $size/2) $percent = $percent * $size/2/$min; // Ensure that minimum axis size is size/2
		$new_x = round($this->x*$percent);
		$new_y = round($this->y*$percent);
		$dst = ImageCreateTrueColor($new_x,$new_y);
		imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
		if(imagecopyresampled($dst,$this->image,0,0,0,0,$new_x,$new_y,$this->x,$this->y)) {
			$this->image = $dst;
			$this->x=imagesx($this->image);
			$this->y=imagesy($this->image);
			return true;
		} 
		return false;
	}

	function save($filename) {
		if (!$this->image) return false;
		return imagejpeg($this->image, $filename);
	}

	function get() {
		$res = get_url($this->url, $this->referer);
		$this->checked = true;
		if ($res) {
			$this->content_type = $res['content_type'];
			return $this->fromstring($res['content']);
		} 
		echo "<!-- Failed to get $this->url-->\n";
		return false;
	}

	function fromstring($imgstr) {
		$this->checked = true;
		$this->image = @imagecreatefromstring($imgstr);
		if ($this->image !== false) {
			$this->x = imagesx($this->image);
			$this->y = imagesy($this->image);
			return true;
		}
		$this->x = $this->y = 0;
		$this->type = 'error';
		echo "<!-- GET error: $this->url: $this->x, $this->y-->\n";
		return false;
	}



}

class WebThumb extends BasicThumb {
	protected static $visited = array();
	public $candidate = false;
	public $html_x = 0;
	public $html_y = 0;

	function __construct($imgtag = '', $referer = '') {
		if (!$imgtag) return;
		$this->tag = $imgtag;
		
		if (!preg_match('/src *=["\'](.+?)["\']/i', $this->tag, $matches) 
			&& !preg_match('/src *=([^ ]+)/i', $this->tag, $matches)) { // Some sites don't use quotes
			if (!preg_match('/["\']*([\da-z\/]+\.jpg)["\']*/i', $this->tag, $matches)) {
				return;
			}
		} else {
			// Avoid maps, headers and such
			if (preg_match('/usemap=|header/i',  $this->tag)) return;
		}

		parent::__construct($matches[1], $referer);
		$this->type = 'local';

		if (strlen($this->url) < 5 || WebThumb::$visited[$this->url] ) return;
		WebThumb::$visited[$this->url] = true;

		if(preg_match('/[ "]width *[=:][ \'"]*(\d+)/i', $this->tag, $match)) {
			$this->html_x = $this->x = intval($match[1]);
		}
		if(preg_match('/[ "]height *[=:][ \'"]*(\d+)/i', $this->tag, $match)) {
			$this->html_y = $this->y = intval($match[1]);
		}

		// First filter to avoid downloading very small images
		if (($this->x > 0 && $this->x < 100) || ($this->y > 0 && $this->y < 100)) {
			$this->candidate = false;
			return;
		}

		if (!preg_match('/button|banner|\Wban[_\W]|\Wads\W|\Wpub\W|logo|header|rss/i', $this->url) 
				/*&& (
				// Check if domain.com are the same for the referer and the url
				preg_replace('/.*?([^\.]+\.[^\.]+)$/', '$1', $this->parsed_url['host']) == preg_replace('/.*?([^\.]+\.[^\.]+)$/', '$1', $this->parsed_referer['host']) 
				|| preg_match('/images\W|wp-content\W|upload\W|imgs\W|pics\W|pictures\W/', $this->url) 
				|| preg_match('/gfx\.|cdn\.|imgs*\.|\.img|media\.|cache\.|\.cache|static\.|\.ggpht.com|upload|files|blogspot|blogger|wordpress\.com|pic\./', $this->parsed_url['host'])
				)
			*/
			) {
			$this->candidate = true;
			//echo "Candidate: $this->x, $this->y $url -> $this->url<br>\n";
		}
	}

	function get() {
		if( !parent::get() ) return false;
		// Ensure we use the html "virtual" size
		// to avoid the selection of images scaled down in the page
		if ($this->html_x == 0 && $this->html_y == 0) {
			$this->html_x = $this->x;
			$this->html_y = $this->y;
		} elseif ($this->html_x == 0) {
			$this->html_x = intval($this->html_y * $this->x / $this->y);
		} else {
			$this->html_y = intval($this->html_x * $this->y / $this->x);
		}
		return true;
	}

	function surface() {
		return $this->html_x * $this->html_y;
	}

	function diagonal() {
		return (int) sqrt(pow($this->html_x, 2) + pow($this->html_y, 2));
	}

	function ratio() {
		return (max($this->html_x, $this->html_y) / min($this->html_x, $this->html_y));
	}

	function max() {
		return max($this->html_x, $this->html_y);
	}


	function good() {
		if ($this->candidate && ! $this->checked) {
			$this->get();
			$x = $this->html_x;
			$y = $this->html_y;
		}
		if (preg_match('/\/gif/i', $this->content_type) || preg_match('/\.gif/', $this->url)) {
			$min_size = 140;
			$min_surface = 35000;
		} else {
			$min_size = 100;
			$min_surface = 24000;
		}
		return $x >= $min_size && $y >= $min_size && ( 
			(($x*$y) > $min_surface && $this->ratio() < 3.5) || 
			( $x > $min_size*4 && ($x*$y) > $min_surface*3 && $this->ratio() < 4.6)); // For panoramic photos
	}

}

class HtmlImages {
	public $html = '';
	public $alternate_html = '';
	public $selected = false;

	function __construct($url, $site = false) {
		$this->url = $url;
		$this->parsed_url = parse_url($url);
		$this->base = $url;
		$this->site = $site;
	}

	function get() {
		$res = get_url($this->url);
		if (!$res) return;
		if (preg_match('/^image/i', $res['content_type'])) {
			$img = new BasicThumb($this->url);
			if ($img->fromstring($res['content'])) {
				$img->type = 'local';
				$img->candidate = true;
				$this->selected = $img;
			}
		} elseif (preg_match('/text\/html/i', $res['content_type'])) {
			$this->html = $res['content'];
			$this->title = get_html_title(&$this->html);

			// First check for thumbnail head metas
			if (preg_match('/<link +rel=[\'"]image_src[\'"] +href=[\'"](.+?)[\'"].*?>/is', $this->html, $match) ||
				preg_match('/<meta +name=[\'"]thumbnail_url[\'"] +content=[\'"](.+?)[\'"].*?>/is', $this->html, $match)) {
				$url = $match[1];
				echo "<!-- Try to select from $url -->\n";
				$img = new BasicThumb($url);
				if ($img->get()) {
					$img->type = 'local';
					$img->candidate = true;
					$this->selected = $img;
					echo "<!-- Selected from $img->url -->\n";
					return $this->selected;
				}
			}


			// Analyze HTML <img's
			if (preg_match('/<base *href=["\'](.+?)["\']/i', $this->html, $match)) {
				$this->base = $match[1];
			}
			$html_short = $this->shorten_html($this->html);
			//echo "<!-- $html_short -->\n";
			$this->parse_img(&$html_short);

			// If there is no image or image is slow
			// Check if there are players
			if ((!$this->selected || $this->selected->surface() < 75000) && preg_match('/(<|&lt;)(embed|object|param)/i', $this->html)) {
				echo "<!-- Searching for video -->\n";
				if ($this->check_youtube()) return $this->selected;
				if ($this->check_google_video()) return $this->selected;
				if ($this->check_metacafe()) return $this->selected;
				if ($this->check_vimeo()) return $this->selected;
				if ($this->check_zapp_internet()) return $this->selected;
				if ($this->check_daily_motion()) return $this->selected;
			}

		}
		return $this->selected;
	}

	function shorten_html($html) {
			$html = preg_replace('/^.*?<body[^>]*?>/is', '', $html); // Search for body
			$html = preg_replace('/<*!--.*?-->/s', '', $html); // Delete commented HTML
			$html = preg_replace('/<style[^>]*?>.+?<\/style>/is', '', $html); // Delete javascript
			$html = preg_replace('/<script[^>]*?>.*?<\/script>/is', '', $html); // Delete javascript
			$html = preg_replace('/<noscript[^>]*?>.*?<\/noscript>/is', '', $html); // Delete javascript
			$html = preg_replace('/[ ]{3,}/ism', '', $html); // Delete useless spaces
			/* $html = preg_replace('/^.*?<h1[^>]*?>/is', '', $html); // Search for a h1 */
			$html = substr($html, 0, 55000); // Only analyze first X bytes
			return $html;
	}

	function parse_img($html) {
		$this->get_other_html();
		preg_match_all('/(<img\s.+?>|["\'][\da-z\/]+\.jpg["\'])/is', $html, $matches);
		if (! $matches) return false;
		$goods = $n = 0;
		foreach ($matches[0] as $match) {
			if ($this->check_in_other($match)) continue;
			$img = new WebThumb($match, $this->base);
			if ($img->candidate && $img->good()) {
				$goods++;
				$img->coef = intval($img->surface()/$img->max());
				echo "\n<!-- CANDIDATE: ". htmlentities($img->url)." X: $img->html_x Y: $img->html_y Surface: ".$img->surface()." Coef1: $img->coef Coef2: ".intval($img->coef/1.33)." -->\n";
				if (!$this->selected || ($this->selected->coef < $img->coef/1.33)) {
					$this->selected = $img;
					$n++;
					echo "<!-- SELECTED: ". htmlentities($img->url)." X: $img->html_x Y: $img->html_y -->\n";
				}
			}
			if ($goods > 5 && $n > 0) break;
		}
		if ($this->selected && ! $this->selected->image) {
			$this->selected->get();
		}
		return $this->selected;
	}

	function get_other_html() {
		// Tries to find an alternate page to check for "common images" and ignore them
		$this->other_html = false;
		if ($this->html) {
			$regexp = '[a-z]+?:\/\/'.preg_quote($this->parsed_url['host']).'\/[^\"\'>]+?';
			if ($this->site) {
				$parsed = parse_url($this->site);
				if ($parsed['host'] != $this->parsed_url['host']) {
					$regexp .= preg_quote($this->site, '/').'\/[^\"\'>]+?';
				}
			}
			$regexp .= '|[\/\.][^\"\']+?|\w[^\"\':]+?';

			$selection = array();
		
			if (preg_match_all("/<a\s[^>]*href=[\"\']($regexp)[\"\']/is",$this->html,$matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					$weight = 1;
					$url = urldecode($match[1]);
					$parsed_match = parse_url($url);
					if ( preg_match('/\.(gif|jpg|zip|png|jpeg|rar|mp3|mov|mpeg|mpg)($|\s)/i', $url) ||
						(!empty($this->parsed_url['query']) && $this->parsed_url['query'] == $parsed_match['query']) ||
						substr($parsed_match['path'].$parsed_match['query'], 0, 45) == 
							substr($this->parsed_url['path'].$this->parsed_url['query'], 0, 45) 
|| 
						preg_match('/feed|rss|atom|trackback/i', $match[1])) {
						continue;
					}

					// Assign weights
					if (!empty($parsed_match['query'])) {
						if (empty($this->parsed_url['query'])) $weight *= 0.5;
						elseif ($this->parsed_url['path'] == $parsed_match['path']) $weight *= 2;
					}
					$equals = path_equals($parsed_match['path'], $this->parsed_url['path']);
					if ($equals > 0) {
						$weight *= 1.1 * $equals;
					}

					$url = build_full_url(trim($url), $this->url);
					$weight *= strlen($url);
					$key = sprintf('%08.2f:%s', $weight, $url);
					if (!$selection[$key]) {
						$selection[$key] = $url;
					}
				}
				if (count($selection) > 1) { // we avoid those simple pages with only a link to itself or home
					krsort($selection);
					$n = 0;
					$paths = array();
					foreach ($selection as $key => $url) {
						$parsed = parse_url($url);
						$first_path = path_sub_path($parsed['path'], 2);
						if ($paths[$first_path] > 1) {
							echo "<!-- Skipped path count > 2: $url -->\n";
							continue;
						}
						$res = get_url($url, $this->url);
						echo "<!-- Other: read $key -->\n";
						if ($res && preg_match('/text\/html/i', $res['content_type']) && 
								$this->title != get_html_title($res['content']) &&
								preg_match('/<img.+?>/',$res['content'])
							) {
							$n++;
							$this->other_html .= $this->shorten_html($res['content']). "<!-- END part $n -->\n";
							if ($n > 2) break;
							$paths[$first_path] = $paths[$first_path] + 1;
						}
					}
				}
			}
		}
		return $this->other_html;
	}

	function check_in_other($str) {
		if (preg_match('/'.preg_quote($str,'/').'/', $this->other_html)) {
				echo "<!-- Skip: " . htmlentities($str). "-->\n";
				return true;
		}
		return false;
	}

	// VIDEOS

	// Google Video detection
	function check_google_video() {
		if (preg_match('/=["\']http:\/\/video\.google\.[a-z]{2,5}\/.+?\?docid=(.+?)&/i', $this->html, $match) &&
				(preg_match('/video\.google/', $this->parsed_url['host']) || ! $this->check_in_other($match[1]))) {
			$video_id = $match[1];
			echo "<!-- Detect Google Video, id: $video_id -->\n";
			if ($video_id) {
				$url = $this->get_google_thumb($video_id);
				if($url) {
					$img = new BasicThumb($url);
					if ($img->get()) {
						$img->type = 'local';
						$img->candidate = true;
						$this->selected = $img;
						echo "<!-- Video selected from $img->url -->\n";
						return $this->selected;
					}
				}
			}
		}
		return false;
	}

	function get_google_thumb($videoid) {
		if(($res = get_url("http://video.google.com/videofeed?docid=$videoid"))) {
			$vrss = $res['content'];
			if($vrss) {
				preg_match('/<media:thumbnail url=["\'](.+?)["\']/',$vrss,$thumbnail_array);
				return $thumbnail_array[1];
			}
		}
		return false;
	}

	// Youtube detection
	function check_youtube() {
		if (preg_match('/http:\/\/www\.youtube\.com\/v\/(.+?)[\"\'&]/i', $this->html, $match) &&
			(preg_match('/youtube\.com/', $this->parsed_url['host']) || ! $this->check_in_other($match[1]))) {
			$video_id = $match[1];
			echo "<!-- Detect Youtube, id: $video_id -->\n";
			if ($video_id) {
				$url = $this->get_youtube_thumb($video_id);
				if($url) {
					$img = new BasicThumb($url);
					if ($img->get()) {
						$img->type = 'local';
						$img->candidate = true;
						$this->selected = $img;
						echo "<!-- Video selected from $img->url -->\n";
						return $this->selected;
					}
				}
			}
		}
		return false;
	}

	function get_youtube_thumb($videoid) {
		$thumbnail = false;
		if(($res = get_url("http://gdata.youtube.com/feeds/api/videos/$videoid"))) {
			$vrss = $res['content'];
			$previous = 0;
			if($vrss && 
				preg_match_all('/<media:thumbnail url=["\'](.+?)["\'].*?width=["\'](\d+)["\']/',$vrss,$matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					if ($match[2] > $previous) {
						$thumbnail = $match[1];
						$previous = $match[2];
					}
				}
			}
		}
		return $thumbnail;
	}

	// Metaface detection
	function check_metacafe() {
		if (preg_match('/=["\']http:\/\/www\.metacafe\.com\/fplayer\/(\d+)\//i', $this->html, $match) &&
				(preg_match('/metacafe\.com/', $this->parsed_url['host']) || ! $this->check_in_other($match[1]))) {
			$video_id = $match[1];
			echo "<!-- Detect Metacafe, id: $video_id -->\n";
			if ($video_id) {
				$url = $this->get_metacafe_thumb($video_id);
				if($url) {
					$img = new BasicThumb($url);
					if ($img->get()) {
						$img->type = 'local';
						$img->candidate = true;
						$this->selected = $img;
						echo "<!-- Video selected from $img->url -->\n";
						return $this->selected;
					}
				}
			}
		}
		return false;
	}

	function get_metacafe_thumb($videoid) {
		if(($res = get_url("http://www.metacafe.com/api/item/$videoid"))) {
			$vrss = $res['content'];
			if($vrss) {
				preg_match('/<media:thumbnail url=["\'](.+?)["\']/',$vrss,$thumbnail_array);
				return $thumbnail_array[1];
			}
		}
		return false;
	}

	// Vimeo detection
	function check_vimeo() {
		if (preg_match('/=["\']http:\/\/vimeo\.com\/moogaloop\.swf\?clip_id=(\d+)/i', $this->html, $match) &&
				(preg_match('/vimeo\.com/', $this->parsed_url['host']) || ! $this->check_in_other($match[1]))) {
			$video_id = $match[1];
			echo "<!-- Detect Vimeo, id: $video_id -->\n";
			if ($video_id) {
				$url = $this->get_vimeo_thumb($video_id);
				if($url) {
					$img = new BasicThumb($url);
					if ($img->get()) {
						$img->type = 'local';
						$img->candidate = true;
						$this->selected = $img;
						echo "<!-- Video selected from $img->url -->\n";
						return $this->selected;
					}
				}
			}
		}
		return false;
	}

	function get_vimeo_thumb($videoid) {
		if(($res = get_url("http://vimeo.com/api/clip/$videoid.xml"))) {
			$vrss = $res['content'];
			if($vrss) {
				preg_match('/<thumbnail_large>(.+)<\/thumbnail_large>/i',$vrss,$thumbnail_array);
				return $thumbnail_array[1];
			}
		}
		return false;
	}

	// ZappInternet Video detection
	function check_zapp_internet() {
		if (preg_match('#http://zappinternet\.com/v/([^&]+)#i', $this->html, $match) &&
				(preg_match('/zappinternet\.com/', $this->parsed_url['host']) || ! $this->check_in_other($match[1]))) {
			$video_id = $match[1];
			echo "<!-- Detect Zapp Internet Video, id: $video_id -->\n";
			if ($video_id) {
				$url = $this->get_zapp_internet_thumb($video_id);
				if($url) {
					$img = new BasicThumb($url);
					if ($img->get()) {
						$img->type = 'local';
						$img->candidate = true;
						$this->selected = $img;
						echo "<!-- Video selected from $img->url -->\n";
						return $this->selected;
					}
				}
			}
		}

		return false;
	}

	function get_zapp_internet_thumb($videoid) {
		return 'http://zappinternet.com/videos/'.substr($videoid, 0, 1).'/frames/'.$videoid.'.jpg';
	}

	// Daily Motion Video detection
	function check_daily_motion() {
		if (preg_match('#=["\']http://www.dailymotion.com/swf/([^&"\']+)#i', $this->html, $match) &&
				(preg_match('/dailymotion\.com/', $this->parsed_url['host']) || ! $this->check_in_other($match[1]))) {
			$video_id = $match[1];
			echo "<!-- Detect Daily Motion Video, id: $video_id -->\n";
			if ($video_id) {
				$url = $this->get_daily_motion_thumb($video_id);
				if($url) {
					$img = new BasicThumb($url);
					if ($img->get()) {
						$img->type = 'local';
						$img->candidate = true;
						$this->selected = $img;
						echo "<!-- Video selected from $img->url -->\n";
						return $this->selected;
					}
				}
			}
		}
		return false;
	}

	function get_daily_motion_thumb($videoid) {
		return 'http://www.dailymotion.com/thumbnail/160x120/video/'.$videoid;
	}

}

function build_full_url($url, $referer) {
	$parsed_url = parse_url($url);
	$parsed_referer = parse_url($referer);

	if (preg_match('/^\/\//', $url)) { // it's an absolute url wihout http:
            return $parsed_referer['scheme']."$url";
	} elseif (! $parsed_url['scheme']) {
		$fullurl = $parsed_referer['scheme'].'://'.$parsed_referer['host'];
		if ($parsed_referer['port']) $fullurl .= ':'.$parsed_referer['port'];
		if (!preg_match('/^\/+/', $parsed_url['path'])) {
			$fullurl .= normalize_path(dirname($parsed_referer['path']).'/'.$parsed_url['path']);
		} else {
			$fullurl .= $parsed_url['path'];
		}
		if ($parsed_url['query']) $fullurl .= '?'.$parsed_url['query'];
		return $fullurl;
	}
	return $url;

}
function normalize_path($path) {
	$path = preg_replace('~/\./~', '/', $path);
    // resolve /../
    // loop through all the parts, popping whenever there's a .., pushing otherwise.
	$parts = array();
	foreach (explode('/', preg_replace('~/+~', '/', $path)) as $part) {
		if ($part === "..") {
			array_pop($parts);
		} elseif ($part) {
			$parts[] = $part;
		}
	}
	return '/' . implode("/", $parts);
}

function path_sub_path($path, $level = -1) {
	$parts = array();
	$dirs = explode('/',  preg_replace('#^/+#', '', $path));
	$count = count($dirs);
	if ($level < 0) $n = $count - $level;
	else  $n = $level;
	for ($i=0; $i<$n && $i<$count; $i++) {
			$parts[] = $dirs[$i];
	}
	return '/' . implode("/", $parts);
}

function path_equals($path1, $path2) {
	$parts1 = explode('/', preg_replace('#^/+#', '', $path1));
	$parts2 = explode('/', preg_replace('#^/+#', '', $path2));
	$n = 0;
	$max = min(count($parts1), count($parts2));
	for ($i=0; $i < $max && $parts1[$i] == $parts2[$i]; $i++) $n++;
	return $n;
}


function get_url($url, $referer = false) {
	global $globals;
	static $session = false;
	static $previous_host = false;

	$url = html_entity_decode($url);
	$parsed = parse_url($url);
	if (!$parsed) return false;

	if ($session && $previous_host != $parsed['host']) {
		curl_close($session);
		$session = false;
	}
	if (!$session) {
		$session = curl_init();
		$previous_host =  $parsed['host'];
	}
	curl_setopt($session, CURLOPT_URL, $url);
	curl_setopt($session, CURLOPT_USERAGENT, $globals['user_agent']);
	if ($referer) curl_setopt($session, CURLOPT_REFERER, $referer); 
	curl_setopt($session, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($session, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($session, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($session, CURLOPT_MAXREDIRS, 20);
	curl_setopt($session, CURLOPT_TIMEOUT, 20);
	curl_setopt($session,CURLOPT_FAILONERROR,true);
	$result['content'] = curl_exec($session);
	if (!$result['content']) return false;
	$result['content_type'] = curl_getinfo($session, CURLINFO_CONTENT_TYPE);
	return $result;
}

function get_html_title($html) {
	if(preg_match('/<title[^<>]*>([^<>]*)<\/title>/si', $html, $matches))
		return $matches[1];
	return false;
}


?>
