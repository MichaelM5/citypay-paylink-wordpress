<?php
/**
 * Plugin Name: CityPay Paylink WP
 * Plugin URI: http://citypay.com/paylink
 * Description: Include an arbitrary payment processing form.
 * Version: 1.0.0
 * Author: CityPay Limited
 * Author URI: http://citypay.com
 */

defined('ABSPATH') or die;

define(CP_PAYLINK_DISPATCHER, 'cp_paylink');
define(CP_PAYLINK_MERCHANT_ID, 'cp_paylink_merchant_id');
define(CP_PAYLINK_LICENCE_KEY, 'cp_paylink_licence_key');
define(CP_PAYLINK_TEST_MODE, 'cp_paylink_test_mode');
define(CP_PAYLINK_DEBUG_MODE, 'cp_paylink_debug_mode');
    
define(CP_PAYLINK_EMAIL_REGEX, '/^[A-Za-z0-9_.+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]*)$/');

define(CP_PAYLINK_NO_ERROR, 0x00);

define(CP_PAYLINK_AMOUNT_PARSE_ERROR, -1);
define(CP_PAYLINK_AMOUNT_PARSE_ERROR_EMPTY_STRING, -2);
define(CP_PAYLINK_AMOUNT_PARSE_ERROR_INVALID_CHARACTER, -3);
define(CP_PAYLINK_AMOUNT_PARSE_ERROR_INVALID_PRECISION, -4);
define(CP_PAYLINK_AMOUNT_PARSE_ERROR_BELOW_MINIMUM_VALUE, -5);
define(CP_PAYLINK_AMOUNT_PARSE_ERROR_ABOVE_MAXIMUM_VALUE, -6);

define(CP_PAYLINK_DEFAULT_MINIMUM_AMOUNT, 0);

define(CP_PAYLINK_TEXT_FIELD_PARSE_ERROR, 0x01);

define(CP_PAYLINK_EMAIL_ADDRESS_FIELD_PARSE_ERROR, 0x03);

require_once('includes/stack.php');

function cp_paylink_config_stack() {
    static $cp_paylink_config_stack = NULL;
    if (is_null($cp_paylink_config_stack)) {
        $cp_paylink_config_stack = new cp_paylink_config_stack();
    }
    return $cp_paylink_config_stack;
}

/*function cp_paylink_enqueue_styles() {
    wp_enqueue_style('parent-style', get_template_directory_uri().'/style.css');
    wp_enqueue_style('child-style', get_stylesheet_uri(), array('parent-style'));
}*/

/*function cp_paylink_enqueue_javascript() {
    wp_enqueue_script('jquery', 'http://ajax.googleapis.com/ajax/libs/jquery/1.11.1/jquery.min.js');
    wp_enqueue_script('paylink', 'https://secure.citypay.com/paylink3/js/paylink-api-1.0.0.min.js', array('jquery'));
}*/

function cp_paylink_send_cors_headers($headers) {
    error_log("send_cors_headers: ".$headers);
    $headers['Access-Control-Allow-Origin'] = "https://secure.citypay.com";
    return $headers;
}

function cp_paylink_add_query_vars_filter($vars) {
    $vars[] = "cp_paylink";
    return $vars;
}

function cp_paylink_payform_field_config_sort($v1, $v2) {   
    if ($v1->order > $v2->order) { return 1; }
    else if ($v1->order < $v2->order) { return -1; }
    else { return 0; }
}
        
class cp_paylink_field {
    public $label, $name, $order, $placeholder;
    public $value, $error;
    public function __construct($name, $label, $placeholder = '', $order = 99) {
        $this->name = $name;
        $this->label = $label;
        $this->placeholder = $placeholder;
        $this->order = $order;
    }
    
    protected function parse($value_in, &$value_out) {
        $this->value = $value_in;
        $value_out = $value_in;
        return true;
    }
}

class cp_paylink_amount_field extends cp_paylink_field {
    private $decimal_places, $minimum, $maximum;
    
    private function _parse_amount($in, &$out, $decimal_places = null)
    {
        $_in = trim($in);
        $_out = 0;
        $i = 0; $i_max = strlen($_in);
        
        if ($i_max <= 0x00) {
            return CP_PAYLINK_AMOUNT_PARSE_ERROR_EMPTY_STRING;
        }
        
        while ($i < $i_max) {
            $c = ord($_in[$i]);
            if ($c >= 48 && $c <= 57) {
                $_out = ($_out * 10) + ($c - 48);
                $i++;
            } else if ($c == ord('.')) {
                break;
            } else {
                return CP_PAYLINK_AMOUNT_PARSE_ERROR_INVALID_CHARACTER;
            }
        }
        
        $_out *= 100;
        
        if ($i >= $i_max) {
            $out = $_out;
            return CP_PAYLINK_NO_ERROR;
        }
        
        if ($c == ord('.')) {
            $i++;
            $pence = 0;
            
            if (!is_null($decimal_places) && $i_max > $i + $decimal_places) {
                return CP_PAYLINK_AMOUNT_PARSE_ERROR_INVALID_PRECISION;
            }
            
            $j = $decimal_places;
            while ($i < $i_max) {
                $c = ord($_in[$i]);
                if ($c >= 48 && $c <= 57) {
                    $pence = ($pence * 10) + ($c - 48);
                    $i++; $j--;
                } else {
                    return CP_PAYLINK_AMOUNT_PARSE_ERROR_INVALID_CHARACTER;
                }
            }

            if ($j > 0x00) { $pence = $pence * pow(10, $j); }
            
            $_out += $pence;
        }
        
        $out = $_out;
        return CP_PAYLINK_NO_ERROR;
    }
    
    public function __construct($name, $label, $placeholder = '', $order = 99, $decimal_places = null, $minimum = null, $maximum = null) {
        $r = parent::__construct($name, $label, $placeholder, $order);
        if ($r) {
            if (is_null($decimal_places)) {
                $this->decimal_places = null;
            } else {
                $this->decimal_places = intval($decimal_places);
            }
            
            if (is_null($minimum)) {
                $this->minimum = null;
            } else {
                $r = self::_parse_amount($minimum, $this->minimum);
                if (!$r) {
                    // TODO: raise exception
                }
            }
            
            if (is_null($maximum)) {
                $this->maximum = null;
            } else {
                $r = self::_parse_amount($maximum, $this->maximum);
                if (!$r) {
                    // TODO: raise exception
                }
            }
        } else {
            // TODO: raise exception
        }
    }
     
    public function parse($value_in, &$value_out, $decimal_places = null) {
        $value = 0;
        $r = parent::parse($value_in, $value);
        if (!$r) {
            // this should relate to an upstream error - TODO: restructure
            // parse functionality to return more finely grained errors
            // at all levels.
            $this->error = CP_PAYLINK_NO_ERROR;
            return false;
        }
        
        $_decimal_places = (!is_null($decimal_places)?$decimal_places:$this->decimal_places);
        $r2 = self::_parse_amount($value, $value_out, $_decimal_places);
        if ($r2 == CP_PAYLINK_NO_ERROR) {
            if (!is_null($this->minimum) && $value_out < $this->minimum) {
                $this->error = CP_PAYLINK_AMOUNT_PARSE_ERROR_BELOW_MINIMUM_VALUE;
                return false;
            } else if (!is_null($this->maximum) && $value_out > $this->maximum) {
                $this->error = CP_PAYLINK_AMOUNT_PARSE_ERROR_ABOVE_MAXIMUM_VALUE;
                return false;
            } else {
                $this->error = CP_PAYLINK_NO_ERROR;
                return true;
            }
        } else {
            $this->error = $r2;
            return false;
        }
    }
}

class cp_paylink_email_field extends cp_paylink_field {
    public function parse($value_in, &$value_out) {
        $r = parent::parse($value_in, $value_out);
        if ($r) {
            $r = preg_match(CP_PAYLINK_EMAIL_REGEX, $this->value);
            if ($r) {
                $value_out = $this->value;
            } else {
                $this->error = CP_PAYLINK_EMAIL_ADDRESS_FIELD_PARSE_ERROR;
            }
        }
        return $r;
    }
}

class cp_paylink_text_field extends cp_paylink_field {
    public $pattern;
   
    public function __construct($name, $label, $placeholder = '', $pattern = '', $order = 99) {
        parent::__construct($name, $label, $placeholder, $order);
        $this->pattern = $pattern;
    }
    
    public function parse($value_in, &$value_out) {
        return parent::parse($value_in, $value_out);
    }
}

function cp_paylink_payform_field($attrs) {
    $a = shortcode_atts(
            array(
                    'label' => '',
                    'name' => '',
                    'order' => 99,
                    'placeholder' => '',
                    'pattern' => '',
                    'type' => 'text'
                ),
            $attrs
        );
        
    switch ($a['type'])
    {
    case 'amount':
        $field = new cp_paylink_amount_field($a['name'], $a['label'], $a['placeholder'], $a['order']);
        break;
    
    case 'email-address':
        $field = new cp_paylink_email_field($a['name'], $a['label'], $a['placeholder'], $a['order']);
        break;
        
    case 'text':
    default:
        $field = new cp_paylink_text_field($a['name'], $a['label'], $a['placeholder'], $a['pattern'], $a['order']);
        break;
    }
   
    cp_paylink_config_stack()->set($field->name, $field);
    
    return '';
}

function cp_paylink_shortcode_sink($attrs, $content = null) {
    if (!is_null($content)) {
        //cp_paylink_config_stack()->push_new();
        do_shortcode($content);
    }
    return '';
}

class cp_paylink_tag {
    public $tag;
    public $attrs;
    public $start;
    public $end;
    public $tag_type;
    public $is_matched;

    public function __construct($tag, $attrs, $start, $end, $tag_type) {
        $this->tag = $tag;
        $this->attrs = $attrs;
        $this->start = $start;
        $this->end = $end;
        $this->tag_type = $tag_type;
    }
}

class cp_paylink_attr {
    public $name;
    public $value;

    public function __construct($name, $value) {
        $this->name = $name;
        $this->value = $value;
    }
}

class cp_paylink_text {
    public $text;

    public function __construct($text) {
        $this->text = $text;
    }
}

function cp_paylink_get_tag($s, &$i, &$i_max)
{
    $j = $i++;
    
    $c = $s[$i];
    if ($c != "/") {
        $tag_type = CP_PAYLINK_OPENING_TAG;
    } else {
        $tag_type = CP_PAYLINK_CLOSING_TAG;
        $i++;
    }

    $tag = '';
    while ($i < $i_max) {
        $c = $s[$i++];
        if ($c == ">") {
            break;
        } else if ($c == " " || $c == "/r" || $c == "/n" || $c == "/t") { 
            break;
        } else {
            $tag .= $c;
            $i++;
        }
    }

    $attrs = array();
    if ($c == " " || $c == "/r" || $c == "/n" || $c == "/t")
    {
        while ($i < $i_max)
        {
            // purge whitespace
            while (++$i < $i_max) {
                $c = $s[$i];
                if ($c != " " && $c != "/r" && $c != "/n" && $c != "/t") {
                    break;
                }
            }

            if ($c == "/" && $tag_type == CP_PAYLINK_OPENING_TAG) {
                $tag_type = CP_PAYLINK_SELF_CLOSING_TAG;
            }

            $attr_name = '';
            $attr_value = null;
            while ($i < $i_max) {
                $c = $s[$i];
                if ($c == "=" || $c == " " || $c == "/r" || $c == "/n" || $c == "/t") {
                    break;
                } else {
                    $attr_name .= $c;
                    $i++;
                }
            }

            if ($c == "=") { 
                $i++;
                $attr_value = '';
                while ($i < $i_max) {
                    $c = $s[$i];
                    if ($c == " " || $c == "/r" || $c == "/n" || $c == "/t") {
                        break;
                    } else {
                        $attr_value .= $c;
                        $i++;
                    }
                }
            }

            $attrs[] = new attr($attr_name, $attr_value);
        }
    }

    if ($c == ">")
    {
        switch ($tag_type)
        {
        case CP_PAYLINK_OPENING_TAG:
            //$stack[] = new tag($tag, $)
            break;

        case CP_PAYLINK_SELF_CLOSING_TAG:
        case CP_PAYLINK_CLOSING_TAG:

            break;
        }
    }

    $tag_lc = strtolower($tag);
    $tag_obj = tag($tag_lc, $attrs, $j, $i, $tag_type);
}

function cp_paylink_trim_outer_p_and_br_tags($s) {

    $stack = array();
    $content = array();
    
    $i = 0;
    $i_max = strlen($s);
    while ($i < $i_max) {
        // purge whitespace
        while ($i < $i_max) {
            $c = $s[$i++];
            if ($c != " " && $c != "\n" && $c != "\r" && $c != "\t") { break; }
        }
        
        if ($i >= $i_max) { break; }
        
        if ($c == "<") {
            $stack[] = &$tag_obj;
            $content[] = &$tag_obj;
        } else {
            /*while ($i < $i_max) {
                if 
            }*/
        }
    }
}


function cp_paylink_trim_p_and_br_tags($s) {
    $i = 0;
    $i_max = strlen($s);
    while ($i < $i_max) {
        $c = $s[$i];
        if ($c == "<") {
            $k = $i++;
            $tag = '';
            while ($i < $i_max) {
                $c = $s[$i++];
                if ($c != ">") {
                    $tag .= $c;
                } else {
                    break;
                }
            }
            $tag_lc = strtolower($tag);
            if ($tag_lc != "br" && $tag_lc != "br/" && $tag_lc != "br /") {
                $i = $k;
                break;
            }
        } else if ($c == " " || $c == "\n" || $c == "\r" || $c == "\t") {
            // do nothing
            $i++;
        } else {
            break;
        }
    }
    
    $j = $i_max - 1;
    while ($j > $i) {
        $c = $s[$j];
        if ($c == ">") {
            $k = $j + 1;
            $tag = "";
            while ($j > $i) {
                $c = $s[$j--];
                if ($c != "<") {
                    $tag .= $c;
                } else {
                    break;
                }
            }
            $tag_lcr = strrev(strtolower($tag));
            if ( $tag_lcr != "br" && $tag_lcr != "br/" && $tag_lcr != "br /") {
                $j = $k;
                break;
            }
        } else if ($c == " " || $c == "\n" || $c == "\r" || $c == "\t") {
            // do nothing
            $j--;
        } else {
            break;
        }
    }
    
    /*echo '<pre>';
    var_dump(bin2hex($s));
    var_dump($i_max);
    var_dump($i);
    var_dump($j);
    var_dump(bin2hex(substr($s, $i, ($j - $i))));
    var_dump(bin2hex(substr($s, 0, $j + 1)));
    echo '</pre>';*/
    
    return substr($s, $i, ($j - $i));
}

function cp_paylink_shortcode_passthrough($attrs, $content = null) {
    $a = shortcode_atts(
            array(),
            $attrs
        );
    
    if (!is_null($content)) {
        $s = cp_paylink_trim_p_and_br_tags($content);
        return do_shortcode($s);
    } else {
        return '';
    }
}

function cp_paylink_payform_display($attrs, $content = null) {
    $a = shortcode_atts(
            array('submit' => __('Pay', cp_paylink_pay)),
            $attrs
        );
    
    if (is_single() || is_page())
    {
        // if a configuration has been specified
        $current_url = get_permalink();        
        $s = trim($content)
           .'<form role="form" id="billPaymentForm" class="form-horizontal" method="POST" action="'
           .add_query_arg('cp_paylink', 'pay', $current_url)
           .'"><input type="hidden" name="cp_paylink_pay" value="Y">';
                
        $config = cp_paylink_config_stack()->peek();
        // sort config according to the order attribute
        
        usort($config, cp_paylink_payform_field_config_sort);
        
        foreach ($config as $field) {
            $s .= '<div class="form-group">'
                .'<label class="com-sm-2 control-label">'
                .$field->label
                .'</label><div class="col-sm-10"><input class="form-control" name="'
                .$field->name
                .'" type="text" value="';
            
            $s .= $field->value;              
                        
            $s .= '" placeholder="'
                .$field->placeholder
                .'">';
            
            if (!is_null($field->error)) {
                switch ($field->error)
                {
                case CP_PAYLINK_TEXT_FIELD_PARSE_ERROR:
                    $s .= '<em>Text field parse error</em>';
                    break;
                
                case CP_PAYLINK_AMOUNT_FIELD_PARSE_ERROR:
                    $s .= '<em>Amount field parse error</em>';
                    break;
                
                case CP_PAYLINK_EMAIL_ADDRESS_FIELD_PARSE_ERROR:
                    $s .= '<em>Email address field parse </em>';
                    break;
                    
                case CP_PAYLINK_AMOUNT_PARSE_ERROR_EMPTY_STRING:
                    $s .= '<em>Amount field parse error: empty string</em>';
                    break;
                
                case CP_PAYLINK_AMOUNT_PARSE_ERROR_INVALID_CHARACTER:
                    $s .= '<em>Amount field parse error: invalid character</em>';
                    break;
                
                case CP_PAYLINK_AMOUNT_PARSE_ERROR_INVALID_PRECISION:
                    $s .= '<em>Amount field parse error: invalid precision</em>';
                    break;
                
                case CP_PAYLINK_AMOUNT_PARSE_ERROR_BELOW_MINIMUM_VALUE:
                    $s .= '<em>Amount field parse error: below minimum value</em>';
                    break;
                
                case CP_PAYLINK_AMOUNT_PARSE_ERROR_ABOVE_MAXIMUM_VALUE:
                    $s .= '<em>Amount field parse error: above maximum value</em>';
                    break;
                }
            }
            
            $s .= '</div></div>';
        }
        
        $s .= '<button type="submit">'
           .$a['submit']
           . '</button></form>';
        
        return $s;
    } else {
        return '';
    }
}

function cp_paylink_payform_on_page_load($attrs, $content = null) {
    //
    //  If shortcode contains nested shortcodes, process these before
    //  processing the immediate form.
    //
    if (!is_null($content)) {
        $s = cp_paylink_trim_p_and_br_tags($content);
        return do_shortcode($s);
    } else {
        return '';
    }
}

function cp_paylink_action_pay() {
    require_once('includes/logger.php');
    require_once('includes/paylink.php');

    $page_id = get_query_var('page_id');
    $page_post = get_post($page_id);

    add_shortcode('citypay-payform-field', 'cp_paylink_payform_field');
    add_shortcode('citypay-payform-on-page-load', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform-on-redirect-success', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform-on-redirect-failure', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform-on-redirect-cancel', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform', 'cp_paylink_shortcode_sink');

    do_shortcode($page_post->post_content);
    
    $merchant_id = get_option(CP_PAYLINK_MERCHANT_ID);
    $licence_key = get_option(CP_PAYLINK_LICENCE_KEY);
    $test_mode = get_option(CP_PAYLINK_TEST_MODE);
    $identifier_out = '';
    $email_out = '';
    $name_out = '';
    $amount_out = 0;

    $f_valid = true;
    
    //echo '<pre>';
    //var_dump($f_valid);
    $f1 = cp_paylink_config_stack()->get('identifier');
    $identifier_in = filter_input(INPUT_POST, 'identifier', FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
    if (!is_null($f1) && !is_null($identifier_in)) {
        $f_valid &= $f1->parse($identifier_in, $identifier_out);
    //    var_dump($f_valid);
    }
    
    $f2 = cp_paylink_config_stack()->get('email');
    $email_in = filter_input(INPUT_POST, 'email', FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
    if (!is_null($f2) && !is_null($email_in)) {
        $f_valid &= $f2->parse($email_in, $email_out);
    //    var_dump($f_valid);
    }
    
    // Note: Name field had to be renamed to customer-name, as name field is
    // a Wordpress field that (presumably) relates to the name of either a link
    // or a particular blog post (using the slug). Consequently, on entering
    // text into the 'name' page, Wordpress was attempting to resolve the
    // name in preference to the page identifier with the result that a page
    // not found error / template page was being generated and output.
    //
    // May require an element of white / black listing on field names to avoid
    // this situation, particularly if caused by users.
    $f3 = cp_paylink_config_stack()->get('customer-name');
    $name_in = filter_input(INPUT_POST, 'customer-name', FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
    if (!is_null($f3) && !is_null($name_in)) {
        $f_valid &= $f3->parse($name_in, $name_out);
    //    var_dump($f_valid);
    }
 
    $f4 = cp_paylink_config_stack()->get('amount');
    $amount_in = filter_input(INPUT_POST, 'amount', FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
    if (!is_null($f4) && !is_null($amount_in)) {
        $f_valid &= $f4->parse($amount_in, $amount_out);
    //    var_dump($f_valid);
    }
       
        /*echo 'identifier_in = "'.$identifier_in.'"; ';
        echo 'identifier_out = "'.$identifier_out.'"; ';
        echo 'email_in = "'.$email_in.'"; ';
        echo 'email_out = "'.$email_out.'"; ';
        echo 'name_in = "'.$name_in.'"; ';
        echo 'name_out = "'.$name_out.'"; ';
        echo 'amount_out = "'.$amount_out.'";';
        var_dump($f_valid);
        echo '</pre>';
        //exit;*/
    
    if (!$f_valid) { return false; }
   
    $current_url = add_query_arg('page_id', $page_id, get_home_url());
    $postback_url = add_query_arg(CP_PAYLINK_DISPATCHER, 'postback', $current_url);
    $success_url = add_query_arg(CP_PAYLINK_DISPATCHER, 'success', $current_url);
    $failure_url = add_query_arg(CP_PAYLINK_DISPATCHER, 'failure', $current_url);
    
    $x = new CityPay_PayLink(new logger());
    $x->setRequestCart(
            $merchant_id,
            $licence_key,
            $identifier_out,
            $amount_out
        );
    $x->setRequestAddress($name_out, '', '', '', '', '', '', '', $email_out);
    $x->setRequestClient('Wordpress', get_bloginfo('version', 'raw'));
    $x->setRequestConfig(
            $test_mode,
            $postback_url,
            $success_url,
            $failure_url
        );
    try {
        $url = $x->getPaylinkURL();
        wp_redirect($url);
        exit;
    } catch (Exception $ex) {
        echo '<pre>'.$ex.'<pre>';
    }
    
    return false;
}

function cp_paylink_init() {    
    if (isset($_GET[CP_PAYLINK_DISPATCHER])) {
        add_filter('query_vars', 'cp_paylink_add_query_vars_filter');
        add_action('template_redirect', 'cp_paylink_template_redirect_dispatcher');
    } else {
        add_shortcode('citypay-payform-display', 'cp_paylink_payform_display');
        add_shortcode('citypay-payform-field', 'cp_paylink_payform_field');
        add_shortcode('citypay-payform-on-page-load', 'cp_paylink_payform_on_page_load');
        add_shortcode('citypay-payform-on-redirect-success', 'cp_paylink_shortcode_sink');
        add_shortcode('citypay-payform-on-redirect-failure', 'cp_paylink_shortcode_sink');
        add_shortcode('citypay-payform-on-redirect-cancel', 'cp_paylink_shortcode_sink');
        add_shortcode('citypay-payform', 'cp_paylink_shortcode_passthrough');
        add_action('admin_menu', 'cp_paylink_administration');
        //add_filter('wp_headers', array('cp_paylinkjs_send_cors_headers'));
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'cp_paylink_settings_link');
    }
}

function cp_paylink_wp_loaded() {
    return;
}

function cp_paylink_template_redirect_on_redirect_failure()
{
    $page_id = get_query_var('page_id');
    $page_post = get_post($page_id);

    add_shortcode('citypay-payform-field', 'cp_paylink_payform_field');
    add_shortcode('citypay-payform-on-page-load', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform-on-redirect-success', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform-on-redirect-failure', 'cp_paylink_shortcode_passthrough');
    add_shortcode('citypay-payform-on-redirect-cancel', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform', 'cp_paylink_shortcode_passthrough');
    
    do_shortcode($page_post->post_content);
}

function cp_paylink_template_redirect_on_postback()
{
    ob_clean();
    header('HTTP/1.1 200 OK');
    exit;
}

function cp_paylink_template_redirect_on_redirect_success()
{
    $page_id = get_query_var('page_id');
    $page_post = get_post($page_id);

    add_shortcode('citypay-payform-field', 'cp_paylink_payform_field');
    add_shortcode('citypay-payform-on-page-load', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform-on-redirect-success', 'cp_paylink_shortcode_passthrough');
    add_shortcode('citypay-payform-on-redirect-failure', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform-on-redirect-cancel', 'cp_paylink_shortcode_sink');
    add_shortcode('citypay-payform', 'cp_paylink_shortcode_passthrough');

    do_shortcode($page_post->post_content);
}

function cp_paylink_template_redirect_dispatcher() {
    
    //echo '<pre>'.$_GET[CP_PAYLINK_DISPATCHER].'</pre>';
    
    if (isset($_GET[CP_PAYLINK_DISPATCHER])) {
        $action = $_GET[CP_PAYLINK_DISPATCHER];
        switch ($action)
        {
        case 'pay':
            $r = cp_paylink_action_pay();
            if (!$r) {
                //echo '<pre>Display the page with errors</pre>';
                
                //$page_id = get_query_var('page_id');
                //$page_post = get_post($page_id);
                //echo '<pre>';
                //var_dump(the_ID());
                //var_dump(get_current_blog_id());
                //var_dump($GLOBALS['wp']);
                //echo '</pre>';
                
                remove_shortcode('citypay-payform');
                remove_shortcode('citypay-payform-on-redirect-success');
                remove_shortcode('citypay-payform-on-redirect-failure');
                remove_shortcode('citypay-payform-on-redirect-cancel');
                remove_shortcode('citypay-payform-on-page-load');
                remove_shortcode('citypay-payform-display');
                remove_shortcode('citypay-payform-field');
                add_shortcode('citypay-payform-display', 'cp_paylink_payform_display');
                add_shortcode('citypay-payform-field', 'cp_paylink_shortcode_sink');
                add_shortcode('citypay-payform-on-page-load', 'cp_paylink_shortcode_passthrough');
                add_shortcode('citypay-payform-on-redirect-success', 'cp_paylink_shortcode_sink');
                add_shortcode('citypay-payform-on-redirect-failure', 'cp_paylink_shortcode_sink');
                add_shortcode('citypay-payform-on-redirect-cancel', 'cp_paylink_shortcode_sink');
                add_shortcode('citypay-payform', 'cp_paylink_shortcode_passthrough');
                add_action('admin_menu', 'cp_paylink_administration');
                //add_filter('wp_headers', array('cp_paylinkjs_send_cors_headers'));
                add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'cp_paylink_settings_link');
            }
            break;

        case 'postback':
            cp_paylink_template_redirect_on_postback();
            break;

        case 'success':
            cp_paylink_template_redirect_on_redirect_success();
            break;

        case 'failure':
            cp_paylink_template_redirect_on_redirect_failure();
            break;

        default:
            break;
        }
    }
}

function cp_paylink_settings_link($links) {
    $settings_link = '<a href="options-general.php?page=cp-paylink-settings">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

function cp_paylink_administration() {
    add_options_page( 
        __('CityPay PayLink WP', 'cp-paylink-wp'),
        __('CityPay PayLink WP', 'cp-paylink-wp'),
        'manage_options',
        'cp-paylink-settings',
        'cp_paylink_settings_page'
    );
}

function cp_paylink_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    $hidden_field_name = 'cp_paylink_payform_hidden_field';
    
    $merchant_id_option_value = get_option(CP_PAYLINK_MERCHANT_ID, '');
    $licence_key_option_value = get_option(CP_PAYLINK_LICENCE_KEY, '');
    $test_mode_option_value = get_option(CP_PAYLINK_TEST_MODE, true);
    $debug_mode_option_value = get_option(CP_PAYLINK_DEBUG_MODE, true);
    
    echo '<div class="">';
    echo '<h2>'.__( 'CityPay PayLink WP', 'cp-paylink-wp').'</h2>';

    if (isset($_POST[$hidden_field_name]) && $_POST[$hidden_field_name] == 'Y') {
       
        $merchant_id_option_value = filter_input(INPUT_POST, CP_PAYLINK_MERCHANT_ID, FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
        update_option(CP_PAYLINK_MERCHANT_ID, $merchant_id_option_value);
        
        $licence_key_option_value = filter_input(INPUT_POST, CP_PAYLINK_LICENCE_KEY, FILTER_DEFAULT, FILTER_REQUIRE_SCALAR);
        update_option(CP_PAYLINK_LICENCE_KEY, $licence_key_option_value);
        
        $test_mode_option_value = (filter_input(INPUT_POST, CP_PAYLINK_TEST_MODE, FILTER_DEFAULT, FILTER_REQUIRE_SCALAR) == 'on');  
        update_option(CP_PAYLINK_TEST_MODE, $test_mode_option_value);
        
        $debug_mode_option_value = (filter_input(INPUT_POST, CP_PAYLINK_DEBUG_MODE, FILTER_DEFAULT, FILTER_REQUIRE_SCALAR) == 'on');  
        update_option(CP_PAYLINK_DEBUG_MODE, $debug_mode_option_value);
        
        echo '<div class="updated below-h2"><p><strong>'
            .__('Updated settings saved.', 'updated-settings-saved')
            .'</strong></p></div>';
    }
    
    ?>
    
<form name="form1" method="post" action="">
<input type="hidden" name="<?php echo $hidden_field_name; ?>" value="Y">
<table class="form-table">
    <tbody>
    <tr>
        <th class="titledesc"><label><?php _e("Merchant ID", 'merchant-id'); ?></label></th>
        <td class="forminp"><input type="text" name="<?php echo CP_PAYLINK_MERCHANT_ID; ?>" value="<?php echo $merchant_id_option_value; ?>" size="16"></input></td>
    </tr>
    <tr>
        <th class="titledesc"><label><?php _e("Licence key", 'licence-key'); ?></label></th>
        <td class="forminp"><input type="text" name="<?php echo CP_PAYLINK_LICENCE_KEY; ?>" value="<?php echo $licence_key_option_value; ?>" size="16"></input></td>
    </tr>
    <tr>
        <th class="titledesc"><label><?php _e("Test Mode", 'test-mode'); ?></label></th>
        <td class="forminp">
            <fieldset>
                <label><input type="checkbox" name="<?php echo CP_PAYLINK_TEST_MODE; ?>" <?php echo ($test_mode_option_value?'checked':''); ?>></input>
                    Generate transactions using test mode
                </label><p class="description">Use this whilst testing your integration. You must disable test mode when you are ready to take live transactions.</p>
            </fieldset>
        </td>
    </tr>
    <tr>
        <th class="titledesc"><label><?php _e("Debug Mode", 'debug-mode'); ?></label></th>
        <td class="forminp">
            <fieldset>
                <label><input type="checkbox" name="<?php echo CP_PAYLINK_DEBUG_MODE; ?>" <?php echo ($debug_mode_option_value?'checked':''); ?>></input>
                    Enable logging
                </label><p class="description">Log payment events, such as postback requests, inside <code>XXXXX</code>.</p>
            </fieldset>
        </td>
    </tr>
    </tbody>
</table>
<p class="submit">
<input type="submit" name="Submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
</p>

</form>
    
    <?php
    
    echo '</div>';
}

add_action('init', 'cp_paylink_init');
add_action('wp_loaded', 'cp_paylink_wp_loaded');
