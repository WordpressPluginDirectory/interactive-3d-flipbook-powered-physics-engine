<?php
  namespace iberezansky\fb3d;
  use \WP_Query;

  function convert_tax_to_tax_query($tax) {
    $ids = explode(',', $tax);
    $iids = array();
    foreach($ids as $id) {
      array_push($iids, intval($id));
    }
    return array(array(
      'taxonomy'=> POST_ID.'-category',
  		'field'=> 'term_id',
  		'terms'=> $iids
    ));
  }

  function convert_query_to_array($q) {
    $a = json_decode(str_replace('\'', '"', $q), true);
    return $a===null? []: $a;
  }

  function template_url_to_path($url) {
    $url = str_replace('\\', '/', $url);
    $dir = str_replace('\\', '/', DIR);
    $wp_content = str_replace('\\', '/', WP_CONTENT_DIR);
    $wp_content = substr($wp_content, strrpos($wp_content, '/'));
    $pattern = $wp_content.'/plugins/';
    return substr($dir, 0, strpos($dir, $pattern)).substr($url, strpos($url, $pattern));
  }

  function fetch_url_to_js_data($url) {
    global $fb3d;
    if(!isset($fb3d['jsData']['urls'][$url])) {
      $fb3d['jsData']['urls'][$url] = file_get_contents(template_url_to_path($url));
    }
  }

  function fetch_js_data() {
    global $fb3d;
    $posts = client_posts_in($fb3d['jsData']['posts']['ids_mis'], $fb3d['jsData']['posts']['ids']);
    $fb3d['jsData']['posts']['ids_mis'] = [];
    $fb3d['jsData']['posts']['ids'] = [];

    $pages = client_posts_in_pages($fb3d['jsData']['pages']);
    $fb3d['jsData']['pages'] = [];

    $firstPages = client_posts_in_first_page($fb3d['jsData']['firstPages']);
    $fb3d['jsData']['firstPages'] = [];

    $jsData = [
      'posts'=> [],
      'pages'=> [],
      'firstPages'=> []
    ];

    foreach ($posts as $post) {
      $jsData['posts'][$post['ID']] = $post;
    }

    foreach ($pages as $page) {
      if(!isset($jsData['pages'][$page['page_post_ID']])) {
        $jsData['pages'][$page['page_post_ID']] = [];
      }
      array_push($jsData['pages'][$page['page_post_ID']], $page);
    }

    foreach ($firstPages as $page) {
      $jsData['firstPages'][$page['page_post_ID']] = $page;
    }

    return $jsData;
  }

  function load_js_data($a) {
    global $fb3d;
    $jsData = null;

    if($a['mode']!=='thumbnail') {
      client_book_control_props();
      get_book_templates();
    }

    if($a['id']!=='0') {
      array_push($fb3d['jsData']['posts'][in_array($a['mode'], ['thumbnail', 'thumbnail-lightbox'])? 'ids_mis': 'ids'], $a['id']);

      if($a['mode']!=='thumbnail') {
        array_push($fb3d['jsData']['pages'], $a['id']);
      }
      else {
        array_push($fb3d['jsData']['firstPages'], $a['id']);
      }

      $jsData = fetch_js_data();
    }

    if($a['mode']!=='thumbnail') {
      $template = $a['template'];
      if($template==='default') {
        $template = aa(aa($fb3d['jsData']['bookCtrlProps'], 'skin'), 'default', 'short-white-book-view');
        if($template==='auto') {
          $template = 'short-white-book-view';
        }
        $a['template'] = $template;
      }

      if(!isset($fb3d['templates'][$template])) {
        $template = 'short-white-book-view';
        $a['template'] = $template;
      }

      if($a['lightbox']==='default') {
        $a['lightbox'] = aa(aa($fb3d['jsData']['bookCtrlProps'], 'lightbox'), 'default', 'auto');
        if($a['lightbox']==='auto') {
          $a['lightbox'] = 'dark-shadow';
        }
      }
    }

    return ['atts'=> $a, 'jsData'=> $jsData];
  }

  function update_templates_cache() {
    global $fb3d;
    $us = [];
    foreach($fb3d['templates'] as $t) {
      $us[$t['html']] = 1;
      $us[$t['script']] = 1;
      foreach($t['styles'] as $s) {
        $us[$s] = 1;
      }
    }
    $urls = [];
    foreach($us as $u=>$v) {
      $urls[substr($u, strpos($u, '/plugins/')+9)] = file_get_contents(template_url_to_path($u));
    }

    $path = template_url_to_path(ASSETS_JS.'skins-cache.js');
    $old = file_exists($path)? file_get_contents($path): '';
    $new = implode('', [
      'FB3D_CLIENT_LOCALE.templates=', preg_replace('/"http.*?plugins\\\\\//i', '"', json_encode($fb3d['templates'])), ';',
      'FB3D_CLIENT_LOCALE.jsData.urls=', json_encode($urls), ';'
    ]);
    if($old!==$new) {
      file_put_contents($path, $new);
    }
  }

  function get_client_dictionary() {
    global $fb3d;
    $us = [];
    foreach($fb3d['templates'] as $t) {
      $us[$t['html']] = 1;
    }
    $d = [];
    foreach($us as $u=>$v) {
      $html = file_get_contents(template_url_to_path($u));
      preg_match_all('/<\$tr>(.*?)<\/\$tr>/', $html, $matches);
      foreach ($matches[1] as $t) {
        $d[$t] = aa($fb3d['dictionary'], $t, $t);
      }
    }
    return $d;
  }

  function reset_client_scripts_loaded() {
    global $fb3d;
    $fb3d['client_scripts_loaded'] = false;
  }

  add_action('wp_head', '\iberezansky\fb3d\reset_client_scripts_loaded', 1000);

  function client_locale_loader() {
    global $fb3d;
    $out = '';
    if(!$fb3d['client_scripts_loaded']) {
      $fb3d['client_scripts_loaded'] = true;
      wp_enqueue_script('jquery');
      update_templates_cache();
      ob_start();
      ?>
      <script type="text/javascript">
        if(!window.FB3D_CLIENT_LOCALE) {
          window.PDFJS_LOCALE=<?php echo(json_encode(get_pdf_js_locale())) ?>;
          window.FB3D_LOCALE=<?php echo(json_encode(['dictionary'=> get_client_dictionary()])) ?>;
          window.FB3D_CLIENT_LOCALE=<?php echo(json_encode([
            'key'=> POST_ID,
            'ajaxurl'=> admin_url('admin-ajax.php'),
            'pluginsurl'=> substr(URL, 0, strpos(URL, '/plugins/')+9),
            'images'=> ASSETS_IMAGES,
            'jsData'=> $fb3d['jsData'],
            'thumbnailSize'=> get_thumbnail_size()
          ])) ?>;
          function fb3dFetch(url) {
            return new Promise(function(resolve, reject) {
              jQuery.ajax({url: url, dataType: 'text'}).done(resolve).fail(reject);
            });
          }
          function fb3dClientScriptsLoader() {
            if(window.jQuery && typeof jQuery.ajax==='function') {
              var isStable = !Promise.withResolvers || /^((?!chrome|android).)*safari/i.test(navigator.userAgent), pdfJs = PDFJS_LOCALE;
              window.PDFJS_LOCALE={pdfJsCMapUrl: pdfJs.pdfJsCMapUrl, pdfJsWorker: isStable? pdfJs.stablePdfJsWorker: pdfJs.pdfJsWorker};
              Promise.all([
                fb3dFetch('<?php echo(ASSETS_CSS.'client.css?ver='.VERSION) ?>'),
                fb3dFetch('<?php echo(ASSETS_JS.'skins-cache.js?ver='.VERSION) ?>'),
                fb3dFetch(isStable? pdfJs.stablePdfJsLib: pdfJs.pdfJsLib),
                fb3dFetch('<?php echo(ASSETS_JS.'three.min.js?ver=125') ?>'),
                fb3dFetch('<?php echo(ASSETS_JS.'html2canvas.min.js?ver=0.5') ?>'),
                fb3dFetch('<?php echo(ASSETS_JS.'client.min.js?ver='.VERSION) ?>'),
              ]).then(function(fs) {
                jQuery('head').append(['<style type="text/css">', fs[0], '</style>'].join(''));
                for(var i = 1; i<fs.length; ++i) {
                  eval(fs[i]);
                }
              });
            }
            else {
              setTimeout(fb3dClientScriptsLoader, 100);
            }
          }
          fb3dClientScriptsLoader();
        }
      </script>
      <?php
      $out = ob_get_clean();
    }
    return $out;
  }

  function to_single_quotes($s) {
    return str_replace('"', '\'', $s);
  }

  function shortcode_handler($atts, $content='') {
    $atts = shortcode_atts([
      'id'=> '0',
      'mode'=> 'fullscreen',
      'title'=> 'false',
      'template'=> 'default',
      'lightbox'=> 'default',
      'classes'=> '',
      'urlparam'=> 'fb3d-page',
      'page-n'=>'0',
      'pdf'=> '',
      'tax'=> 'null',
      'thumbnail'=> '',
      'cols'=> '3',
      'style'=> '',
      'query'=> '',
      'book-template'=> 'default',
      'trigger'=> ''
    ], $atts);

    if($atts['tax']==='null') {
      $is_link = $atts['mode']==='link-lightbox';
      $atts['template'] = 'short-white-book-view';
      $classes = str_replace(array(' ', "\t"), '', $atts['classes']);
      $classes = explode(',', $classes);
      array_push($classes, 'fb3d-'.$atts['mode'].'-mode');
      if($atts['mode']==='fullscreen') {
        array_push($classes, 'full-size');
      }
      $classes = implode(' ', $classes);

      $r = load_js_data($atts);
      $atts = $r['atts'];
      $jsData = $r['jsData'];

      $r = sprintf('<%s class="%s %s"', $is_link? 'a ': 'div', '_'.POST_ID, to_single_quotes($classes));
      foreach($atts as $k=> $v) {
        if($k!=='classes' && $k!=='style' && $k!=='query') {
          $r .= sprintf(' data-%s="%s"', $k, to_single_quotes($v));
        }
      }
      if($atts['style']!=='') {
        $r .= sprintf(' style="%s"', to_single_quotes($atts['style']));
      }

      $res = client_locale_loader().($is_link? $r.'>'.$content.'</a>' :$r.'></div>'.$content).($jsData? implode([
      '<script type="text/javascript">',
        'window.FB3D_CLIENT_DATA = window.FB3D_CLIENT_DATA || [];',
        'window.FB3D_CLIENT_DATA.push(\''.base64_encode(json_encode($jsData)).'\');',
        'FB3D_CLIENT_LOCALE.render && FB3D_CLIENT_LOCALE.render();',
      '</script>']): '');
    }
    else {
      $params = ['posts_per_page'=>-1];
  		if($atts['tax']!=='') {
        if(substr($atts['tax'], 0, 1)==='{') {
          $params['tax_query'] = convert_query_to_array($atts['tax']);
        }
  			else {
          $params['tax_query'] = convert_tax_to_tax_query($atts['tax']);
        }
  		}
      $q_params = array_merge($params, convert_query_to_array($atts['query']), ['post_type'=> POST_ID]);
  		$q = new WP_Query($q_params);
  		$params = $atts;
  		$cols = intval($atts['cols']);
  		unset($params['tax']);
      unset($params['style']);
      ob_start();
  		echo('<table class="fb3d-categories" data-query="'.to_single_quotes(json_encode($q_params)).'" data-raw-query="'.to_single_quotes($atts['query']).'" style="'.to_single_quotes($atts['style']).'"><tr>');
  		for($i=0; $i<$q->post_count; ++$i) {
  			if($i%$cols===0 && $i) {
  				echo('</tr><tr>');
  			}
  			$params['id'] = $q->posts[$i]->ID;
  			echo('<td>'.shortcode_handler($params).'</td>');
      }
  		echo('</tr></table>');
      $res = ob_get_clean();
    }

    return $res;
  }

  add_shortcode(POST_ID, '\iberezansky\fb3d\shortcode_handler');
?>
