<?php if(!defined('IS_CMS')) die();

/***************************************************************
* 
* AccessControl Plugin für moziloCMS 2.0
* 
***************************************************************/
class AccessControl extends Plugin {

    public $hasher;
    private $ac_users;
    private $cms_lang;
    public $admin_lang;
    private $admin_info;
    private $current_url;

    function ini_hasher() {
        require_once(BASE_DIR_CMS.'PasswordHash.php');
        $this->hasher = new PasswordHash(8, FALSE);
    }

    function getContent($value) {
        if(!isset($_SESSION['AC_LOGIN_STATUS']))
            $_SESSION['AC_LOGIN_STATUS'] = false;
        if(!isset($_SESSION['AC_LOGIN_COUNT']))
            $_SESSION['AC_LOGIN_COUNT'] = 0;

        global $CMS_CONF;
        $this->cms_lang = new Language(PLUGIN_DIR_REL."AccessControl/sprachen/cms_language_".$CMS_CONF->get("cmslanguage").".txt");

        if(defined("HTTP"))
            $this->current_url = HTTP.$_SERVER['HTTP_HOST'].str_replace('&amp;','&',$_SERVER['REQUEST_URI']);
        else
            $this->current_url = "http://".$_SERVER['HTTP_HOST'].str_replace('&amp;','&',$_SERVER['REQUEST_URI']);
        $this->current_url = str_replace('&','&amp;',$this->current_url);

        $this->ac_users = $this->settings->get('ac_users');
        $this->ini_hasher();
        $this->check_login();

        if($value == "plugin_first") {
            global $CatPage;
            foreach($this->settings->toArray() as $catpage => $users) {
                if(strstr($catpage,FILE_START) !== false and strstr($catpage,FILE_END) !== false) {
                    if(!in_array(AC_LOGGED_USER,$users)) {
                        list($cat,$page) = $CatPage->split_CatPage_fromSyntax($catpage);
                        if($page === false)
                            $CatPage->delete_Cat($cat);
                        else
                            $CatPage->delete_Page($cat,$page);
                    }

                }
            }
            return;
        }
        if($value === false) {
            return null;
        }
        $sep = "|";
        $sep_key_value = "=";
        $sep_user = ",";

        $tmp = explode($sep,$value);
        $value = str_replace($tmp[0].$sep,"",$value);
        $tmp_para = explode($sep_key_value,str_replace(" ","",trim($tmp[0])));
        unset($tmp);

        $art = false;
        $userlist = array();
        if(is_array($tmp_para) and count($tmp_para) == 1) {
            $art = $tmp_para[0];
            if($art !== "login" and $art !== "login_horizontal" and $art !== "logout" and $art !== "any_login" and $art !== "no_login")
                $art = false;
        } elseif(is_array($tmp_para) and count($tmp_para) == 2) {
            if(isset($tmp_para[0]) == 'whitelist') {
                $art = 'whitelist';
                $userlist = explode($sep_user,$tmp_para[1]);
            } elseif(isset($tmp_para[0]) == 'blacklist') {
                $art = 'blacklist';
                $userlist = explode($sep_user,$tmp_para[1]);
            }
        }
        unset($tmp_para);

        if($art) {
            if($art === "login")
                return $this->get_login();
            if($art === "login_horizontal")
                return $this->get_login(true);
            if($art === "logout")
                return $this->get_logout();
            if(AC_LOGGED_USER === false and
                    ($art === "any_login" or $art === "whitelist" or $art === "blacklist"))
                return null;
            if(AC_LOGGED_USER !== false and
                    ($art === "no_login" or
                        ($art === "whitelist" and !in_array(AC_LOGGED_USER,$userlist)) or
                        ($art === "blacklist" and in_array(AC_LOGGED_USER,$userlist))))
                return null;
            return $value;
        } else
            return null;

    }

    function check_login() {
        # Plugin wurde schonnmal aufgerufen
        if(defined('AC_LOGGED_USER'))
            return;
        $action = getRequestValue("ac_action","post",false);
        # Prüfen, ob action=login oder action=logout.
        if(!empty($action)) {
            $user = getRequestValue("ac_user","post",false);
            $pw = getRequestValue("ac_password","post",false);
            if($action == "login" and !empty($user) and !empty($pw)) {
                $_SESSION['AC_LOGIN_STATUS'] = 'login_error';
                if($this->checkLoginData($user, $pw)) {
                    $_SESSION['AC_LOGGED_USER_IN'] = $user;
                    $_SESSION['AC_LOGGED_USER_TIMEOUT'] = time() + $this->ac_users[$user][1];
                    $_SESSION['AC_LOGIN_STATUS'] = 'login_ok';
                }
                $_SESSION['AC_LOGIN_COUNT']++;
                $this->write_log($user);
                if($_SESSION['AC_LOGIN_STATUS'] == 'login_ok')
                    $_SESSION['AC_LOGIN_COUNT'] = 0;
            }

            if($action == "logout") {
                $_SESSION['AC_LOGGED_USER_IN'] = '';
                $_SESSION['AC_LOGGED_USER_TIMEOUT'] = '';
                $_SESSION['AC_LOGIN_COUNT'] = '';
                unset($_SESSION['AC_LOGGED_USER_IN'],$_SESSION['AC_LOGGED_USER_TIMEOUT'],$_SESSION['AC_LOGIN_COUNT']);
            }
        }
        if(isset($_SESSION['AC_LOGGED_USER_IN']) and isset($this->ac_users[$_SESSION['AC_LOGGED_USER_IN']])) {
            $usertime = $this->ac_users[$_SESSION['AC_LOGGED_USER_IN']][1];
            if($usertime > 0 and $_SESSION['AC_LOGGED_USER_TIMEOUT'] < time() - $usertime) {
                unset($_SESSION['AC_LOGGED_USER_IN'],$_SESSION['AC_LOGGED_USER_TIMEOUT'],$_SESSION['AC_LOGIN_COUNT']);
                define('AC_LOGGED_USER',false);
                return;
            }
            define('AC_LOGGED_USER',$_SESSION['AC_LOGGED_USER_IN']);
            $_SESSION['AC_LOGGED_USER_TIMEOUT'] = time() + $usertime;
            return;
        }
        define('AC_LOGGED_USER',false);
    }

    protected function checkLoginData($user, $pass) {
        if(isset($this->ac_users[$user]) and (true === $this->hasher->CheckPassword($pass,$this->ac_users[$user][0]))) {
            return true;
        }
        return false;
    }

    function write_log($user) {
        if($this->settings->get('login_log_enable') != 'true') {
            return;
        }
        # kein log für den user
        if(isset($this->ac_users[$user]) and $this->ac_users[$user][2] === true)
            return;
        $max_status_len = 0;
        foreach(array('login_ok','login_error','login_nouser') as $l) {
            $l = strlen($this->cms_lang->getLanguageValue($l));
            if($l > $max_status_len)
                $max_status_len = $l;
        }
        $file = PLUGIN_DIR_REL."AccessControl/ac_log.php";
        global $page_protect_search, $page_protect;
        if(is_file($file))
            $log = file_get_contents($file);
        else
            $log = "";
        $log = str_replace($page_protect_search,"",$log);

        $new_log = date('Y.m.d | H:i:s');
        $status = $_SESSION['AC_LOGIN_STATUS'];
        if(!isset($this->ac_users[$user]))
            $status = 'login_nouser';

        $new_log .= " | ".$this->cms_lang->getLanguageValue("log_text_status")." ".$this->cms_lang->getLanguageValue($status);
        $new_log .= str_repeat(" ",($max_status_len - strlen($this->cms_lang->getLanguageValue($status))));
        $new_log .= " | ".$this->cms_lang->getLanguageValue("log_text_count")." ".$_SESSION['AC_LOGIN_COUNT'];
        $new_log .= " | ".$this->cms_lang->getLanguageValue("log_text_user")." ".$user."\r\n";

        $login_log_length = $this->settings->get('login_log_length');
        if(!is_numeric($login_log_length))
            $login_log_length = 100;
        if(substr_count($log, "\n") >= $login_log_length) {
            $this->send_mail_log($log);
            $log = "";
        }
        file_put_contents($file,$page_protect.$new_log.$log,LOCK_EX);
    }

    function send_mail_log($log) {
        $from = $this->settings->get('login_log_email_sender');
        if(empty($from))
            return;
        $date = date('Y m d H:i:s');
        $messages = str_replace('{DATE}',$date,$this->cms_lang->getLanguageValue("mail_messages_log"));
        $subject = str_replace('{DATE}',$date,$this->cms_lang->getLanguageValue("mail_subject_log"));

        $filename = str_replace(array(" ",":"),array("_","-"),$date)."_AccessControl_log.txt";

        global $Punycode;
        $from = $Punycode->encode($from);
        require_once(PLUGIN_DIR_REL.'AccessControl/class.phpmailer-lite.php');
        $mail = new PHPMailerLite();
        $mail->IsMail();
        $mail->CharSet = strtolower(CHARSET);
        $mail->SetFrom($from);
        $mail->AddAddress($from);
        $mail->Subject = $subject;
        $mail->AltBody = $messages."\n\n";
        $mail->MsgHTML($messages."<br /><br />");
        $mail->AddStringAttachment($log, $filename,'base64','text/plain');
        $mail->Send();
    }

    function get_logout() {
        if(AC_LOGGED_USER === false)
            return "";
        $tmpl = '<b>{USER}</b> {USER_TEXT}<br />{BUTTON}';
        if(strlen($this->settings->get('logout_config')) > 7)
            $tmpl = $this->settings->get('logout_config');
        $form = '<form accept-charset="'.CHARSET.'" method="post" action="'.$this->current_url.'">'
            .str_replace(array("{USER}","{USER_TEXT}","{BUTTON}"),
                array(htmlentities(AC_LOGGED_USER,ENT_QUOTES,CHARSET),
                    $this->cms_lang->getLanguageValue("logged_in_text"),
                    '<input type="submit" value="'.$this->cms_lang->getLanguageValue("logout").'" />'),
            $tmpl)
            .'<input type="hidden" name="ac_action" value="logout" />'
            .'</form>';
        return '<div class="ac-user-logout">'.$form.'</div>';
    }

    function get_login($horizontal = false) {
        if(AC_LOGGED_USER !== false)
            return "";

        $css_horizontal = "";
        $tmpl = '{ERROR}<ul>'
            .'<li>{USER_TEXT}<br />{INPUT_USER}</li>'
            .'<li>{PW_TEXT}<br />{INPUT_PW}</li>'
            .'<li>{BUTTON}</li>'
            .'</ul>';
        if($horizontal) {
            $css_horizontal = " ac-login-horizontal";
            $tmpl = '{ERROR}<ul>'
                .'<li>{USER_PW_TEXT}</li>'
                .'<li>{INPUT_USER}{INPUT_PW}{BUTTON}</li>'
                .'</ul>';
        }
        if(!$horizontal and strlen($this->settings->get('login_config')) > 7)
            $tmpl = $this->settings->get('login_config');
        elseif($horizontal and strlen($this->settings->get('login_config_horizontal')) > 7)
            $tmpl = $this->settings->get('login_config_horizontal');
        $tmpl_error = "";
        if($_SESSION['AC_LOGIN_STATUS'] == 'login_error') {
            $error_text = $this->cms_lang->getLanguageValue("login_errorcheck");
            if(strlen($this->settings->get('login_user_error')) > 7)
                $error_text = $this->settings->get('login_user_error');
                $tmpl_error = '<div class="ac-login-error">'.$error_text.'</div>';
        }
        $_SESSION['AC_LOGIN_STATUS'] = false;

        $form = '<form accept-charset="'.CHARSET.'" method="post" action="'.$this->current_url.'">'
            .str_replace(array("{USER_TEXT}","{PW_TEXT}","{USER_PW_TEXT}","{INPUT_USER}","{INPUT_PW}","{BUTTON}","{ERROR}"),
                array($this->cms_lang->getLanguageValue("user"),
                    $this->cms_lang->getLanguageValue("pw"),
                    $this->cms_lang->getLanguageValue("user_pw_horizontal"),
                    '<input type="text" name="ac_user" value="" />',
                    '<input type="password" name="ac_password" value="" />',
                    '<input type="submit" value="'.$this->cms_lang->getLanguageValue("login").'" />',
                    $tmpl_error
                ),
            $tmpl)
            .'<input type="hidden" name="ac_action" value="login" /></form>';
        return '<div class="ac-user-login'.$css_horizontal.'">'.$form.'</div>';
    }

    function getConfig() {
        if(IS_ADMIN and $this->settings->get("plugin_first") !== "true") {
            $this->settings->set("plugin_first","true");
        }

        $config = array();
        $config["--admin~~"] = array(
            "buttontext" => $this->admin_lang->getLanguageValue("admin_button"),
            "description" => $this->admin_lang->getLanguageValue("admin_text"),
            "datei_admin" => "admin.php"
        );
        $config['login_log_enable'] = array(
            "type" => "checkbox",
            "description" => $this->admin_lang->getLanguageValue("login_log_enable")
        );
        $config['login_log_length']  = array(
            "type" => "text",
            "description" => $this->admin_lang->getLanguageValue("login_log_length"),
            "maxlength" => "10",
            "size" => "10",
            "regex" => "/^[\d+]+$/",
            "regex_error" => $this->admin_lang->getLanguageValue("login_log_length_error")
        );
        $config['login_log_email_sender']  = array(
            "type" => "text",
            "description" => $this->admin_lang->getLanguageValue("login_log_email_sender"),
            "maxlength" => "50"
        );
        $config['login_config'] = array(
            "type" => "textarea",
            "rows" => "5",
            "description" => $this->admin_lang->getLanguageValue("login_config"),
            'template' => '{login_config_description}<br />{login_config_textarea}'
        );
        $config['login_config_horizontal'] = array(
            "type" => "textarea",
            "rows" => "5",
            "description" => $this->admin_lang->getLanguageValue("login_config_horizontal"),
            'template' => '{login_config_horizontal_description}<br />{login_config_horizontal_textarea}'
        );
        $config['login_user_error']  = array(
            "type" => "textarea",
            "rows" => "3",
            "description" => $this->admin_lang->getLanguageValue("login_user_error"),
            'template' => '{login_user_error_description}<br />{login_user_error_textarea}'
        );
        $config['logout_config'] = array(
            "type" => "textarea",
            "rows" => "5",
            "description" => $this->admin_lang->getLanguageValue("logout_config"),
            'template' => '{logout_config_description}<br />{logout_config_textarea}'
        );
        return $config;
    }

    function getInfo() {
        global $ADMIN_CONF;

        $this->admin_lang = new Language(PLUGIN_DIR_REL."AccessControl/sprachen/admin_language_".$ADMIN_CONF->get("language").".txt");
        $this->admin_info = @file_get_contents(PLUGIN_DIR_REL."AccessControl/sprachen/admin_info_".$ADMIN_CONF->get("language").".txt");

        $info = array(
            "<b>AccessControl</b> Revision: 9",
            "2.0",
            $this->admin_info,
            "stefanbe",
            array("http://www.mozilo.de/forum/index.php?action=media","Templates und Plugins"),
            array(
                '{AccessControl|no_login/any_login|...}' => $this->admin_lang->getLanguageValue("info_selectbox1"),
                '{AccessControl|whitelist/blacklist=user1,user2|...}' => $this->admin_lang->getLanguageValue("info_selectbox2"))
        );
        return $info;
    }
}

?>
