<?php if(!defined('IS_ADMIN') or !IS_ADMIN) die();

class Access_Admin extends AccessControl {

    private $a_settings;
    private $users;
    private $error_message = false;
    private $self_url;
    private $error_users = array();
    private $tabindex = 1;

    function Access_Admin($plugin) {
        $this->a_settings = $plugin->settings;
        $this->self_url = $plugin->PLUGIN_SELF_URL;
        $this->users = $this->get_user_array();
        $tmp_error = array('username' => false,'pw' => false,'pwrepeat' => false,'time' => false);
        $this->error_users["access-newuser"] = $tmp_error;
        foreach($this->users as $user => $tmp) {
            $this->error_users[$user] = $tmp_error;
        }
        unset($tmp_error);
        $this->ini_hasher();
        # das ist wegen der Sprache
        $this->getInfo();
    }

    function out() {
        $html = '';
        $tab_user = ' ui-tabs-selected ui-state-active';
        $tab_log = ' js-hover-default';
        if(getRequestValue('actab',false,false) == "log") {
            $tab_user = ' js-hover-default';
            $tab_log = ' ui-tabs-selected ui-state-active';
        }
        $html .= '<div id="access-admin" class="d_mo-td-content-width ui-tabs ui-widget ui-widget-content ui-corner-all mo-ui-tabs" style="position:relative;width:96%;margin:auto auto;">';
        $html .= '<ul id="js-menu-tabs" class="mo-menu-tabs ui-tabs-nav ui-helper-reset ui-helper-clearfix ui-widget-header ui-corner-top">'
        .'<li class="ui-state-default ui-corner-top'.$tab_user.'"><a href="'.PLUGINADMIN_GET_URL.'&amp;actab=user" title="'.$this->admin_lang->getLanguageValue("tabuser").'" tabindex="'.$this->tabindex.'"><span class="mo-bold">'.$this->admin_lang->getLanguageValue("tabuser").'</span></a></li>';
        if($this->a_settings->get("login_log_enable") == "true")
            $html .= '<li class="ui-state-default ui-corner-top'.$tab_log.'"><a href="'.PLUGINADMIN_GET_URL.'&amp;actab=log" title="'.$this->admin_lang->getLanguageValue("tablog").'" tabindex="'.(++$this->tabindex).'"><span class="mo-bold">'.$this->admin_lang->getLanguageValue("tablog").'</span></a></li>';
        $html .= '</ul>';
        $html .= '<div class="d_plugins mo-ui-tabs-panel ui-widget-content ui-corner-bottom mo-no-border-top">';

        if(getRequestValue('actab',false,false) == "log")
            $html .= $this->get_tab_log();
        else
            $html .= $this->get_tab_user();
        $html .= '</div></div>';
        return $html;
    }

    function get_tab_user() {
        $html = '';
        if(getRequestValue('access-save',"post",false)) {
            $access_post = getRequestValue('access',"post",false);
            $clone_user = false;
            $new_user = false;
            if(!empty($access_post['access-newuser']['access-username'])) {
                if($this->make_new_user($access_post['access-newuser'])) {
                    if(isset($access_post['access-newuser']['access-userclone']) and $access_post['access-newuser']['access-userclone'] != "false") {
                        $clone_user = $access_post['access-newuser']['access-userclone'];
                        $new_user = $access_post['access-newuser']['access-username'];
                    }
                    $access_post[$access_post['access-newuser']['access-username']] = $access_post['access-newuser'];
                }
            }
            unset($access_post['access-newuser']);
            $tmp_catpage = array();
            $tmp_users = array();
            foreach($access_post as $user => $usersettings) {
                if(!isset($access_post[$user]['access-userdel']) and isset($this->users[$user]))
                    $tmp_users[$user] = $this->change_user_settings($access_post[$user],$this->users[$user],$user);
                if(isset($usersettings['access-catpage'])) {
                    foreach($usersettings['access-catpage'] as $catpage) {
                        if($new_user !== false and $clone_user !== false and $clone_user == $user)
                            $tmp_catpage[$catpage][] = $new_user;
                        if(!isset($access_post[$user]['access-userdel']))
                            $tmp_catpage[$catpage][] = $user;
                    }
                }
            }
            foreach($this->a_settings->toArray() as $key => $value) {
                if(strstr($key,FILE_START) !== false and strstr($key,FILE_END) !== false) {
                    if(!array_key_exists($key, $tmp_catpage))
                        $this->a_settings->delete($key);
                }
            }
            $this->a_settings->setFromArray($tmp_catpage);
            $this->a_settings->setFromArray(array('ac_users' => $tmp_users));
        }

        $html .= '<div style="width:100%;" class="mo-td-content-width">'
        .'<form name="allentries" action="'.URL_BASE.ADMIN_DIR_NAME.'/index.php" method="post">'
        .'<input type="hidden" name="pluginadmin" value="'.PLUGINADMIN.'" />'
        .'<input type="hidden" name="action" value="'.ACTION.'" />'
        .'<input type="hidden" name="actab" value="user" />'
        .'<div class="access-bottons-box ui-widget-content ui-corner-all">'
        .'<input type="submit" class="admin-key-descr-submit" name="access-save" value="'.$this->admin_lang->getLanguageValue("save").'" tabindex="'.($this->tabindex + (6 * count($this->users)) + 6).'" />'
        .'</div>'
        .'<ul id="admin-key-descr-content-ul" class="mo-ul">'

        .'<li class="mo-in-ul-li ui-widget-content ui-corner-all ui-state-highlight">'.$this->get_user_tpl("access-newuser").'</li>';
        # user neu einlessen könte ja einer hinzu/gelöscht sein
        $this->users = $this->get_user_array();
        foreach($this->users as $user => $tmp) {
            $html .= '<li class="mo-in-ul-li ui-widget-content ui-corner-all">'.$this->get_user_tpl($user).'</li>';
        }
        $html .= '</ul></form></div>';

        $html .= '<script type="text/javascript">'
        .'$(function() {'
        .'$(".access-select").multiselect({
            showSelectAll:true,
            showClose: false,
            multiple: true,
            selectedList: 0,
            noneSelectedText:"'.$this->admin_lang->getLanguageValue("protect").'",
            selectedText: function(numChecked, numTotal, checkedItems) {
                return $(this.element).attr("title");
            }
        }).multiselectfilter();'
        .'});</script>';
        if($this->error_message !== false) {
            global $message;
            $message = returnMessage(false,'<div class="ui-widget-content ui-corner-all ui-state-highlight" style="padding:.4em;">'.$this->admin_lang->getLanguageValue("error_info").'</div><ul>'.$this->error_message.'</ul>');
        }
        return $html;
    }

    function get_tab_log() {
        if($this->a_settings->get("login_log_enable") != "true")
            return NULL;
        $html = '';
        $file = PLUGIN_DIR_REL."AccessControl/ac_log.php";
        if(!is_file($file)) {
            $html = '<div class="access-log">keine log datei vorhanden</div>';
            return $html;
        }

        $bottons = '<form name="allentries" action="'.URL_BASE.ADMIN_DIR_NAME.'/index.php" method="post">'
        .'<input type="hidden" name="pluginadmin" value="'.PLUGINADMIN.'" />'
        .'<input type="hidden" name="action" value="'.ACTION.'" />'
        .'<input type="hidden" name="actab" value="log" />'

        .'<div class="access-bottons-box ui-widget-content ui-corner-all">'
        .'<input type="submit" class="admin-key-descr-submit" name="access-log-clear" value="'.$this->admin_lang->getLanguageValue("del").'" tabindex="'.(++$this->tabindex).'" />'
        .'<input type="submit" class="admin-key-descr-submit" name="access-log-save" value="'.$this->admin_lang->getLanguageValue("download").'" tabindex="'.(++$this->tabindex).'" />'
        .'</div>'
        .'</form>';
        if(getRequestValue('access-log-clear',"post",false)) {
            global $page_protect;
            file_put_contents($file,$page_protect."",LOCK_EX);
        }

        global $page_protect_search;
        $log_content = file_get_contents($file);
        $log_content = str_replace($page_protect_search,"",$log_content);

        if(getRequestValue('access-log-save',"post",false)) {
            $filename = date('d_m_Y_H-i-s').'_ac_log.txt';
            $file_save = PLUGIN_DIR_REL."AccessControl/".$filename;
            file_put_contents($file_save,$log_content,LOCK_EX);
            $len = filesize($file_save);
            header("Pragma: public");
            header("Expires: 0");
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Cache-Control: public");
            header("Content-Description: File Transfer");
            header("Content-Type: text/plain");
            header("Content-Disposition: attachment; filename=".$filename.";");
            header("Content-Transfer-Encoding: binary");
            header("Content-Length: ".$len);
            @readfile($file_save);
            unlink($file_save);
            exit;
        }

        $html = str_replace(" ","&nbsp;",$log_content);
        $html = str_replace(array("\r\n","\r","\n"),"<br />",$log_content);
        $html = '<pre class="access-log">'.$html.'</pre>';
        return $bottons.$html;
    }


    function get_user_array() {
        $users = $this->a_settings->get('ac_users');
            if(!is_array($users))
                $users = array();
        return $users;
    }

    function get_user_tpl($user) {
        $error_user = '';
        $error_pw = '';
        $error_pwrepeat = '';
        $error_time = '';
        if($this->error_users[$user]['username'])
            $error_user = ' error-in';
        if($this->error_users[$user]['pw'])
            $error_pw = ' error-in';
        if($this->error_users[$user]['pwrepeat'])
            $error_pwrepeat = ' error-in';
        if($this->error_users[$user]['time'])
            $error_time = ' error-in';

        $username = $this->admin_lang->getLanguageValue("newuser");
        $userpw = $this->admin_lang->getLanguageValue("pw");
        $username_in = '<input type="text" class="access-in'.$error_user.'" name="access['.$user.'][access-username]" value="" size="10" tabindex="'.$this->tabindex.'" />';
        $usertime = '0';
        $userdel = '&nbsp;';
        $userlog = '';
        if($user == "access-newuser") {
            $this->tabindex++;
            $html_tpl = $this->make_newuser_select($user);
        } else {
            $html_tpl = $this->make_user_catpage_select($user);
            $tmp = $this->a_settings->get("ac_users");
            $userpw = $this->admin_lang->getLanguageValue("newpw");
            $username = '<b>'.$user.'</b>';
            $username_in = '<input type="hidden" class="" name="access['.$user.'][access-username]" value="'.$user.'" />';
            $usertime = $tmp[$user][1];
            if(isset($tmp[$user][2]) and $tmp[$user][2])
                $userlog = ' checked="checked"';
            $userdel = '<label for="access-'.$user.'">'.$this->admin_lang->getLanguageValue("deluser").'</label><input id="access-'.$user.'" type="checkbox" class="" value="true" name="access['.$user.'][access-userdel]" tabindex="'.($this->tabindex + 5).'" />';
        }

        $html = '<table cellspacing="0" border="0" cellpadding="0">
          <tbody>
            <tr>
              <td class="td1">'.$username.'</td>
              <td class="td2">'.$username_in.'</td>
              <td colspan="2">'.$html_tpl.'</td>
              <td class="td5" align="right">'.$userdel.'</td>
            </tr>
            <tr>
              <td class="td1">'.$userpw.'</td>
              <td class="td2"><input type="password" class="access-in'.$error_pw.'" value="" name="access['.$user.'][access-userpw]" size="10" tabindex="'.$this->tabindex.'" /></td>
              <td class="td3">'.$this->admin_lang->getLanguageValue("accesstime").'</td>
          <td class="td4"><input type="text" class="access-in'.$error_time.'" value="'.$usertime.'" name="access['.$user.'][access-usertime]" size="3" tabindex="'.($this->tabindex + 3).'" /></td>
              <td>&nbsp;</td>
            </tr>
            <tr>
              <td class="td1">'.$this->admin_lang->getLanguageValue("pwrepeat").'</td>
              <td class="td2"><input type="password" class="access-in'.$error_pwrepeat.'" value="" name="access['.$user.'][access-userpwrepeat]" size="10" tabindex="'.($this->tabindex + 1).'" /></td>';
        if($this->a_settings->get("login_log_enable") != "true")
            $html .= '<td class="td3">&nbsp;</td><td class="td4">&nbsp;</td>';
        else
            $html .= '<td class="td3"><label for="access-lb-userlog-'.$user.'">'.$this->admin_lang->getLanguageValue("nolog").'</label></td>
              <td class="td4"><input id="access-lb-userlog-'.$user.'" type="checkbox" class="" value="true" name="access['.$user.'][access-userlog]"'.$userlog.' tabindex="'.($this->tabindex + 4).'" /></td>';
        $html .= '<td>&nbsp;</td>
            </tr>
          </tbody>
        </table>';
        if($user == "access-newuser")
            $this->tabindex--;
        $this->tabindex += 6;
        return $html;
    }

    function check_pw($pw) {
        if(strlen($pw) >= 6
                and preg_match("/[0-9]/", $pw)
            and preg_match("/[a-z]/", $pw)
            and preg_match("/[A-Z]/", $pw))
            return true;
        return false;
    }

    protected function make_pw($newpw) {
        $newpw = $this->hasher->HashPassword($newpw);
#!!!!!!! die fehlermeldung muss geändert werden
        if($newpw == '*')
            return false;
        return $newpw;
    }

    function change_user_settings($user,$settings,$username) {
        $newpw = $user['access-userpw'];
        if(!$newpw or !$this->check_pw($newpw)) {
            if($newpw and !$this->check_pw($newpw)) {
                $this->error_users[$username]['pw'] = true;
                $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_newpwerror").'</li>';
            }
            $newpw = false;
        }

        $newpwrp = $user['access-userpwrepeat'];
        if(!$newpwrp or !$this->check_pw($newpwrp)) {
            $newpwrp = false;
        }

        if($newpw !== false and $newpwrp !== false) {
            if($newpw != $newpwrp) {
                $this->error_users[$username]['pwrepeat'] = true;
                $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_newpwmismatch").'</li>';
                return false;
            }
            if(false === ($pw = $this->make_pw($newpw)))
                return false;
            $settings[0] = $pw;
        }

        if(!isset($user['access-userlog']))
            $user['access-userlog'] = false;
        else
            $user['access-userlog'] = true;

        if(!$user['access-usertime'] or !is_numeric($user['access-usertime'])) {
            if(!is_numeric($user['access-usertime'])) {
                $this->error_users[$username]['time'] = true;
                $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_number").'</li>';
            }
            $user['access-usertime'] = 0;
        }
        $settings[1] = $user['access-usertime'];
        $settings[2] = $user['access-userlog'];
        return $settings;
    }

    function make_new_user($newuser) {
        $user = $newuser['access-username'];
        if(!$user or strlen($user) < 5) {
            if($user and strlen($user) < 5) {
                $this->error_users['access-newuser']['username'] = true;
                $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_tooshortname").'</li>';
            }
            $user = false;
        }
        if($user and str_replace(array(" ",","),"",$user) !== $user) {
            $this->error_users['access-newuser']['username'] = true;
            $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_notallowedcharname").'</li>';
            $user = false;
        }
        if(is_array($this->users) and array_key_exists($user, $this->users)) {
            $this->error_users['access-newuser']['username'] = true;
            $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_newuserexists").'</li>';
            return false;
        }
        $newpw = $newuser['access-userpw'];
        if(!$newpw or !$this->check_pw($newpw)) {
            if($newpw and !$this->check_pw($newpw)) {
                $this->error_users['access-newuser']['pw'] = true;
                $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_newpwerror").'</li>';
            }
            $newpw = false;
        }
        $newpwrp = $newuser['access-userpwrepeat'];
        if(!$newpwrp or !$this->check_pw($newpwrp)) {
            $newpwrp = false;
        }
        if($newpw !== false and $newpwrp !== false and ($newpw != $newpwrp)) {
            $this->error_users['access-newuser']['pwrepeat'] = true;
            $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_newpwmismatch").'</li>';
        }
        if(!isset($user['access-userlog']))
            $newuser['access-userlog'] = false;
        else
            $newuser['access-userlog'] = true;

        if(!$newuser['access-usertime'] or !is_numeric($newuser['access-usertime'])) {
            if(!is_numeric($newuser['access-usertime'])) {
                $this->error_users['access-newuser']['time'] = true;
                $this->error_message .= '<li>'.$this->admin_lang->getLanguageValue("error_number").'</li>';
            }
            $newuser['access-usertime'] = 0;
        }

        if($user !== false and $newpw !== false and $newpwrp !== false and ($newpw == $newpwrp)) {
            if(false === ($pw = $this->make_pw($newpw)))
                return false;
            $this->users[$user] = array($pw,$newuser['access-usertime'],$newuser['access-userlog']);
            $this->a_settings->set('ac_users',$this->users);
            $this->error_users[$user] = $this->error_users["access-newuser"];
            return true;
        }
        return false;
    }

    function make_newuser_select($user) {
        $html_tpl = '';
        if(count($this->users) > 0) {
            $html_tpl .= '<select class="access-newuser-select" name="access['.$user.'][access-userclone]" tabindex="'.($this->tabindex + 2).'">';
            $html_tpl .= '<option value="false">'.$this->admin_lang->getLanguageValue("cpprotect").'</option>';
            foreach($this->users as  $cloneuser => $tmp) {
                $html_tpl .= '<option value="'.$cloneuser.'">'.$cloneuser.'</option>';
            }
            $html_tpl .= '</select><br />';
        }
        return $html_tpl;
    }

    function make_user_catpage_select($user) {
        global $CatPage;
        $html_tpl = '<select class="mo-select access-select" title="'.$this->admin_lang->getLanguageValue("protect").'" name="access['.$user.'][access-catpage][]" multiple="multiple" tabindex="'.($this->tabindex + 2).'">';
        foreach ($CatPage->get_CatArray(false,true,array(EXT_PAGE,EXT_HIDDEN)) as $cat) {
            $selected = '';
            if($this->a_settings->keyExists(FILE_START.$cat.FILE_END) and in_array($user,$this->a_settings->get(FILE_START.$cat.FILE_END)))
                $selected = ' selected="selected"';
            $html_tpl .= '<option value="'.FILE_START.$cat.FILE_END.'"'.$selected.'>'.$CatPage->get_HrefText($cat,false).'</option>';
            foreach ($CatPage->get_PageArray($cat,array(EXT_PAGE,EXT_HIDDEN,EXT_LINK),true) as $page) {
                $selected = '';
                if($this->a_settings->keyExists(FILE_START.$cat.':'.$page.FILE_END) and in_array($user,$this->a_settings->get(FILE_START.$cat.':'.$page.FILE_END)))
                    $selected = ' selected="selected"';
                $html_tpl .= '<option value="'.FILE_START.$cat.':'.$page.FILE_END.'"'.$selected.'>&nbsp;&nbsp;&nbsp;->&nbsp;'.$CatPage->get_HrefText($cat,$page).'</option>';
            }
        }
        $html_tpl .= '</select>';
        return $html_tpl;
    }
}

$Access_Admin = new Access_Admin($plugin);
return $Access_Admin->out();

?>
