<?php
/*
Plugin Name: Gravity Forms YMLP Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with YMLP allowing form submissions to be automatically sent to your YMLP account
Version: 1.0.1
Author: Katz Web Services, Inc.
Author URI: http://www.katzwebservices.com

------------------------------------------------------------------------
Copyright 2012 Katz Web Services, Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFYMLP', 'init'));
register_activation_hook( __FILE__, array("GFYMLP", "add_permissions"));

class GFYMLP {
	
	private static $name = "Gravity Forms YMLP Add-On";
    private static $path = "gravity-forms-ymlp/ymlp.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravity-forms-ymlp";
    private static $version = "1.0.1";
    private static $min_gravityforms_version = "1.3.9";

    //Plugin starting point. Will load appropriate files
    public static function init(){
		global $pagenow;
		
		//loading translations
        load_plugin_textdomain('gravity-forms-ymlp', FALSE, '/gravity-forms-ymlp/languages' );
		
		if($pagenow === 'plugins.php') {
			add_action("admin_notices", array('GFYMLP', 'is_gravity_forms_installed'), 10);
		}
		
		if(self::is_gravity_forms_installed(false, false) === 0){
			add_action('after_plugin_row_' . self::$path, array('GFYMLP', 'plugin_row') );
           return;
        }
		
        if($pagenow == 'plugins.php' || defined('RG_CURRENT_PAGE') && RG_CURRENT_PAGE == "plugins.php"){
        
        	add_action('after_plugin_row_' . self::$path, array('GFYMLP', 'plugin_row') );
            
            add_filter('plugin_action_links', array('GFYMLP', 'settings_link'), 10, 2 );
    
        }

        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravity-forms-ymlp', FALSE, '/gravity-forms-ymlp/languages' );

            add_filter("transient_update_plugins", array('GFYMLP', 'check_update'));
            #add_filter("site_transient_update_plugins", array('GFYMLP', 'check_update'));

            //creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_ymlp")){
                RGForms::add_settings_page("YMLP", array("GFYMLP", "settings_page"), self::get_base_url() . "/images/ymlp_wordpress_icon_32.png");
            }
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFYMLP", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFYMLP', 'create_menu'));

        if(self::is_ymlp_page()){

            //enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));

            //loading data lib
            require_once(self::get_base_path() . "/data.php");


            //loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GFYMLP', 'tooltips'));

            //runs the setup when version changes
            self::setup();

         }
         else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/data.php");

            add_action('wp_ajax_rg_update_feed_active', array('GFYMLP', 'update_feed_active'));
            add_action('wp_ajax_gf_select_ymlp_form', array('GFYMLP', 'select_ymlp_form'));

        }
        else{
             //handling post submission.
            add_action("gform_post_submission", array('GFYMLP', 'export'), 10, 2);
        }
    }
    
    public static function is_gravity_forms_installed($asd = '', $echo = true) {
		global $pagenow, $page; $message = '';
		
		$installed = 0;
		$name = self::$name;
		if(!class_exists('RGForms')) {
			if(file_exists(WP_PLUGIN_DIR.'/gravityforms/gravityforms.php')) {
				$installed = 1;
				
				$message .= __(sprintf('%sGravity Forms is installed but not active. %sActivate Gravity Forms%s to use the %s plugin.%s', '<p>', '<strong><a href="'.wp_nonce_url(admin_url('plugins.php?action=activate&plugin=gravityforms/gravityforms.php'), 'activate-plugin_gravityforms/gravityforms.php').'">', '</a></strong>', $name,'</p>'), 'gravity-forms-ymlp');
				
			} else {
				$message .= <<<EOD
<p><a href="http://katz.si/gravityforms?con=banner" title="Gravity Forms Contact Form Plugin for WordPress"><img src="http://gravityforms.s3.amazonaws.com/banners/728x90.gif" alt="Gravity Forms Plugin for WordPress" width="728" height="90" style="border:none;" /></a></p>
		<h3><a href="http://katz.si/gravityforms" target="_blank">Gravity Forms</a> is required for the $name</h3>
		<p>You do not have the Gravity Forms plugin installed. <a href="http://katz.si/gravityforms">Get Gravity Forms</a> today.</p>
EOD;
			}
			
			if(!empty($message) && $echo) {
				echo '<div id="message" class="updated">'.$message.'</div>';
			}
		} else {
			return true;
		}
		return $installed;
	}
	
	public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("%sGravity Forms%s is required. %sPurchase it today!%s"), "<a href='http://katz.si/gravityforms'>", "</a>", "<a href='http://katz.si/gravityforms'>", "</a>");
            self::display_plugin_message($message, true);
        }
    }
    
    public static function display_plugin_message($message, $is_error = false){
    	$style = '';
        if($is_error)
            $style = 'style="background-color: #ffebe8;"';

        echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFYMLPData::get_feed($id);
        GFYMLPData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //--------------   Automatic upgrade ---------------------------------------------------

    function settings_link( $links, $file ) {
        static $this_plugin;
        if( ! $this_plugin ) $this_plugin = plugin_basename(__FILE__);
        if ( $file == $this_plugin ) {
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_ymlp' ) . '" title="' . __('Select the Gravity Form you would like to integrate with YMLP. Contacts generated by this form will be automatically added to your YMLP account.', 'gravity-forms-ymlp') . '">' . __('Feeds', 'gravity-forms-ymlp') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_settings&addon=YMLP' ) . '" title="' . __('Configure your YMLP settings.', 'gravity-forms-ymlp') . '">' . __('Settings', 'gravity-forms-ymlp') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
        }
        return $links;
    }
    
    
    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_ymlp_page(){
    	global $plugin_page; $current_page = '';
        $ymlp_pages = array("gf_ymlp");
        
        if(isset($_GET['page'])) {
			$current_page = trim(strtolower($_GET["page"]));
		}
		
        return (in_array($plugin_page, $ymlp_pages) || in_array($current_page, $ymlp_pages));
    }


    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_ymlp_version") != self::$version)
            GFYMLPData::update_table();

        update_option("gf_ymlp_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $ymlp_tooltips = array(
            "ymlp_contact_group" => "<h6>" . __("YMLP List", "gravity-forms-ymlp") . "</h6>" . __("Select the YMLP list you would like to add your contacts to.", "gravity-forms-ymlp"),
            "ymlp_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-ymlp") . "</h6>" . __("Select the Gravity Form you would like to integrate with YMLP. Contacts generated by this form will be automatically added to your YMLP account.", "gravity-forms-ymlp"),
            "ymlp_map_fields" => "<h6>" . __("Map Fields", "gravity-forms-ymlp") . "</h6>" . __("Associate your YMLP data fields to the appropriate Gravity Form fields by selecting.", "gravity-forms-ymlp"),
            "ymlp_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-ymlp") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to YMLP when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-ymlp"),

        );
        return array_merge($tooltips, $ymlp_tooltips);
    }

    //Creates YMLP left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_ymlp");
        if(!empty($permission))
            $menus[] = array("name" => "gf_ymlp", "label" => __("YMLP", "gravity-forms-ymlp"), "callback" =>  array("GFYMLP", "ymlp_page"), "permission" => $permission);

        return $menus;
    }

    public static function settings_page(){

        if(isset($_POST["uninstall"])){
            check_admin_referer("uninstall", "gf_ymlp_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms YMLP Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-ymlp")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_ymlp_submit"])){
            check_admin_referer("update", "gf_ymlp_update");
            $settings = array(
            	"username" => stripslashes($_POST["gf_ymlp_username"]), 
            	"api_key" => stripslashes($_POST["gf_ymlp_api_key"]),
            	"debug" => !empty($_POST["gf_ymlp_debug"])
            );
            update_option("gf_ymlp_settings", $settings);
        }
        else{
            $settings = get_option("gf_ymlp_settings");
        }
        
        $api = self::get_api(true);
		$message = '';

        if(!empty($settings["username"]) && !empty($settings["api_key"]) && empty($api->ErrorMessage)) {
            $message = sprintf(__("Valid username and API key. Now go %sconfigure form integration with YMLP%s!", "gravity-forms-ymlp"), '<a href="'.admin_url('admin.php?page=gf_ymlp').'">', '</a>');
            $class = "updated valid_credentials";
        }
        else if(!empty($settings["username"]) || !empty($settings["api_key"]) && !empty($api->ErrorMessage)){
            $message = __("Invalid username and/or API key. Please check your settings.", "gravity-forms-ymlp");
            $class = "error invalid_credentials";
        } else if (empty($settings["username"]) && empty($settings["api_key"])) {
			$message = sprintf(__('%s%sDon\'t have a YMLP account? %sSign up now!%s%s %sYMLP is a simple, reliable, effective email service that lets you create, send and track emails. Over 290,000 users in over 100 countries use YMLP to handle email the simple way.%s%sSign up for an account today%s (it\'s even free with up to 1,00 contacts!)%s%s'), '<h3>', '<a href="http://katz.si/ymlp">', '</a>', '</h3>', '<h4 style="font-size:18px!important;">', '</h4>', '<h4>', '<a href="http://katz.si/ymlp">', '</a>', '</h4>', '<div class="clear"></div>');
			$class = 'updated notice';

        }
        
       ?>
       <a href="http://katz.si/ymlp"><img alt="<?php _e("YMLP Logo", "gravity-forms-ymlp") ?>" src="<?php echo self::get_base_url()?>/images/ymlp-logo.gif" style="margin:7px 7px 15px 0;" width="386" height="74" /></a>
       <?php 
		if($message) {
	        ?>
	        <div id="message" class="<?php echo $class ?>"><?php echo wpautop($message); ?></div>
	        <?php 
        }
        ?>
        <form method="post" action="">
        	<?php wp_nonce_field("update", "gf_ymlp_update") ?>
            <h3><?php _e("YMLP Account Information", "gravity-forms-ymlp") ?></h3>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_ymlp_username"><?php _e("YMLP Account Username", "gravity-forms-ymlp"); ?></label> </th>
                    <td><input type="text" id="gf_ymlp_username" name="gf_ymlp_username" size="30" value="<?php echo !empty($settings["username"]) ? esc_attr($settings["username"]) :  '' ; ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_ymlp_api_key"><?php _e("API Key", "gravity-forms-ymlp"); ?></label> </th>
                    <td><input type="password" id="gf_ymlp_api_key" name="gf_ymlp_api_key" size="40" value="<?php echo !empty($settings["api_key"]) ? esc_attr($settings["api_key"]) : ''; ?>"/><span class="howto"><?php _e('To find your API key, log in to YMLP, then go to the Configuration tab and click on the sub-navigation link "API"', 'gravity-forms-ymlp'); ?></span></td>
                </tr>
                <tr>
                	<th scope="row"><label for="gf_ymlp_api_key"><?php _e("Debug", "gravity-forms-ymlp"); ?></label> </th>
                    <td colspan="2" ><label for="gf_ymlp_debug"><input type="checkbox" id="gf_ymlp_debug" name="gf_ymlp_debug" class="button-primary" <?php checked(!empty($settings['debug']), true); ?> value="1" /> Debug Form Submissions <span class="howto">Only shown to logged-in users who have capability to manage plugin options.</span></label></td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_ymlp_submit" class="button-primary" value="<?php _e("Save Settings", "gravity-forms-ymlp") ?>" /></td>
                </tr>

            </table>
            <div>

            </div>
        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_ymlp_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_ymlp_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall YMLP Add-On", "gravity-forms-ymlp") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL YMLP Feeds.", "gravity-forms-ymlp") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall YMLP Add-On", "gravity-forms-ymlp") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL YMLP Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-ymlp") . '\');"/>';
                    echo apply_filters("gform_ymlp_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    public static function ymlp_page(){
        $view = isset($_GET["view"]) ? $_GET["view"] : '';
        if($view == "edit")
            self::edit_page($_GET["id"]);
        else
            self::list_page();
    }

    //Displays the YMLP feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("The YMLP Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-ymlp"));
        }

        if(isset($_POST["action"]) && $_POST["action"] == "delete"){
            check_admin_referer("list_action", "gf_ymlp_group");

            $id = absint($_POST["action_argument"]);
            GFYMLPData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-ymlp") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_ymlp_group");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFYMLPData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-ymlp") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <a href="http://katz.si/ymlp"><img alt="<?php _e("YMLP Logo", "gravity-forms-ymlp") ?>" src="<?php echo self::get_base_url()?>/images/ymlp-logo.gif" style="margin:15px 7px 0 0;" width="386" height="74" /></a>
            <h2><?php _e("YMLP Feeds", "gravity-forms-ymlp"); ?>
            <a class="button add-new-h2" href="admin.php?page=gf_ymlp&view=edit&id=0"><?php _e("Add New", "gravity-forms-ymlp") ?></a>
            </h2>
			
			<ul class="subsubsub">
	            <li><a href="<?php echo admin_url('admin.php?page=gf_settings&addon=YMLP'); ?>">YMLP Settings</a> |</li>
	            <li><a href="<?php echo admin_url('admin.php?page=gf_ymlp'); ?>" class="current">YMLP Feeds</a></li>
	        </ul>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_ymlp_group') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px; 0">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-ymlp") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-ymlp") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-ymlp") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-ymlp") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-ymlp") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-ymlp") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-ymlp") ?></th>
                            <th scope="col" class="manage-column"><?php _e("YMLP List", "gravity-forms-ymlp") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-ymlp") ?></th>
                            <th scope="col" class="manage-column"><?php _e("YMLP List", "gravity-forms-ymlp") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $settings = GFYMLPData::get_feeds();
                        if(is_array($settings) && !empty($settings)){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-ymlp") : __("Inactive", "gravity-forms-ymlp");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-ymlp") : __("Inactive", "gravity-forms-ymlp");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_ymlp&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-ymlp") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="Edit this setting" href="admin.php?page=gf_ymlp&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-ymlp") ?>"><?php _e("Edit", "gravity-forms-ymlp") ?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Delete", "gravity-forms-ymlp") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-ymlp") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-ymlp") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-ymlp")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date"><?php echo $setting["meta"]["contact_list_name"] ?></td>
                                </tr>
                                <?php
                            }
                        }
                        else { 
                        	$api = self::get_api();
	                        if(!empty($api) && empty($api->lastError)){
	                            ?>
	                            <tr>
	                                <td colspan="4" style="padding:20px;">
	                                    <?php _e(sprintf("You don't have any YMLP feeds configured. Let's go %screate one%s!", '<a href="'.admin_url('admin.php?page=gf_ymlp&view=edit&id=0').'">', "</a>"), "gravity-forms-ymlp"); ?>
	                                </td>
	                            </tr>
	                            <?php
	                        }
	                        else{
	                            ?>
	                            <tr>
	                                <td colspan="4" style="padding:20px;">
	                                    <?php _e(sprintf("To get started, please configure your %sYMLP Settings%s.", '<a href="admin.php?page=gf_settings&addon=YMLP">', "</a>"), "gravity-forms-ymlp"); ?>
	                                </td>
	                            </tr>
	                            <?php
	                        }
	                    }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-ymlp") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-ymlp") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-ymlp") ?>').attr('alt', '<?php _e("Active", "gravity-forms-ymlp") ?>');
                }

                var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-ymlp" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
    }

    private static function get_api($test = false){
        if(!class_exists("YMLP_API"))
            require_once("api/YMLP_API.class.php");
        
        $settings = get_option("gf_ymlp_settings");
        if(empty($settings)) { return false; }
        $secure = true; // Use HTTPS
        $apikey = $settings['api_key'];
        $username = $settings['username'];
        $api = new YMLP_API($apikey, $username, true);
        
        if($test) {
        	$output = $api->Ping(); 
        	
        	if(intval($output['Code']) !== 0) {
	        	$api->ErrorMessage = $output['Output'];
        	}
        }
       	return $api;
    }

    private static function edit_page(){
        ?>
        <style>
            .ymlp_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold;}
            .ymlp_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .gfield_required{color:red;}

            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0;}
            #ymlp_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <a href="http://katz.si/ymlp"><img alt="<?php _e("YMLP Feeds", "gravity-forms-ymlp") ?>" src="<?php echo self::get_base_url()?>/images/ymlp-logo.gif" style="margin:15px 7px 0 0;" width="386" height="74" /></a>
            <h2><?php _e("Setup an YMLP Feed", "gravity-forms-ymlp") ?></h2>

        <?php
        //getting YMLP API
        $api = self::get_api();
		
		//ensures valid credentials were entered in the settings page
        if(!empty($api->ErrorMessage)){
            ?>
            <div class="error" id="message" style="margin-top:20px;"><?php echo wpautop(sprintf(__("We are unable to login to YMLP with the provided username and API key. Please make sure they are valid in the %sSettings Page%s", "gravity-forms-ymlp"), "<a href='?page=gf_settings&addon=YMLP'>", "</a>")); ?></div>
            <?php
            return;
        }

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["ymlp_setting_id"]) ? $_POST["ymlp_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFYMLPData::get_feed($id);
		
		
        //getting merge vars from selected list (if one was selected)
        $merge_vars = empty($config["meta"]["contact_list_id"]) ? array() : self::listMergeVars($config["meta"]["contact_list_id"]);

        //updating meta information
        if(isset($_POST["gf_ymlp_submit"])){
			
			list($list_id, $list_name) = explode("|:|", stripslashes($_POST["gf_ymlp_group"]));
            $config["meta"]["contact_list_id"] = $list_id;
            $config["meta"]["contact_list_name"] = $list_name;
            $config["form_id"] = absint($_POST["gf_ymlp_form"]);

            $is_valid = true;
            $merge_vars = self::listMergeVars($config["meta"]["contact_list_id"]);
            
            $field_map = array();
            foreach($merge_vars as $var){
                $field_name = "ymlp_map_field_" . $var["tag"];
                $mapped_field = isset($_POST[$field_name]) ? stripslashes($_POST[$field_name]) : '';
                if(!empty($mapped_field)){
                    $field_map[$var["tag"]] = $mapped_field;
                }
                else{
                    unset($field_map[$var["tag"]]);
                    if($var["req"] == "Y")
                    $is_valid = false;
                }
                unset($_POST["{$field_name}"]);
            }
            
            // Go through the items that were not in the field map;
            // the Custom Fields
            foreach($_POST as $k => $v) {
            	if(preg_match('/ymlp\_map\_field\_/', $k)) {
            		$tag = str_replace('ymlp_map_field_', '', $k);
            		$field_map[$tag] = stripslashes($_POST[$k]);
	           	}
            }
                        
			$config["meta"]["field_map"] = $field_map;
            #$config["meta"]["double_optin"] = !empty($_POST["ymlp_double_optin"]) ? true : false;
            #$config["meta"]["welcome_email"] = !empty($_POST["ymlp_welcome_email"]) ? true : false;

            $config["meta"]["optin_enabled"] = !empty($_POST["ymlp_optin_enable"]) ? true : false;
            $config["meta"]["optin_field_id"] = $config["meta"]["optin_enabled"] ? isset($_POST["ymlp_optin_field_id"]) ? $_POST["ymlp_optin_field_id"] : '' : "";
            $config["meta"]["optin_operator"] = $config["meta"]["optin_enabled"] ? isset($_POST["ymlp_optin_operator"]) ? $_POST["ymlp_optin_operator"] : '' : "";
            $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? $_POST["ymlp_optin_value"] : "";
			
			
			
            if($is_valid){
                $id = GFYMLPData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-ymlp"), "<a href='?page=gf_ymlp'>", "</a>") ?></div>
                <input type="hidden" name="ymlp_setting_id" value="<?php echo $id ?>"/>
                <?php
            }
            else{
                ?>
                <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-ymlp") ?></div>
                <?php
            }
        }
  //      r($field_map);
//		r($config);
		if(!function_exists('gform_tooltip')) {
			require_once(GFCommon::get_base_path() . "/tooltips.php");
		}

        ?>
        <form method="post" action="">
            <input type="hidden" name="ymlp_setting_id" value="<?php echo $id ?>"/>
            <div class="margin_vertical_10">
                <label for="gf_ymlp_group" class="left_header"><?php _e("YMLP Group", "gravity-forms-ymlp"); ?> <?php gform_tooltip("ymlp_contact_group") ?></label>
                <?php

                //getting all contact lists
                $lists = $api->GroupsGetList();
                
                if (!$lists){
                    echo __("Could not load YMLP contact lists. <br/>Error: ", "gravity-forms-ymlp");
                    echo isset($api->ErrorMessage) ? $api->ErrorMessage : '';
                } else {
                    ?>
                    <select id="gf_ymlp_group" name="gf_ymlp_group" onchange="SelectList(jQuery(this).val());">
                        <option value=""><?php _e("Select a YMLP Group", "gravity-forms-ymlp"); ?></option>
                    <?php
                    foreach ($lists as $list){
                        $selected = $list["ID"] == $config["meta"]["contact_list_id"] ? "selected='selected'" : "";
                        ?>
                        <option value="<?php echo esc_html($list['ID']) . "|:|" . esc_html($list['GroupName']) ?>" <?php echo $selected ?>><?php echo esc_html($list['GroupName']) ?></option>
                        <?php
                    }
                    ?>
                  </select>
                <?php
                }
                ?>
            </div>

            <div id="ymlp_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["contact_list_id"]) ? "style='display:none;'" : "" ?>>
                <label for="gf_ymlp_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-ymlp"); ?> <?php gform_tooltip("ymlp_gravity_form") ?></label>

                <select id="gf_ymlp_form" name="gf_ymlp_form" onchange="SelectForm(jQuery('#gf_ymlp_group').val(), jQuery(this).val());">
                <option value=""><?php _e("Select a form", "gravity-forms-ymlp"); ?> </option>
                <?php
                $forms = RGFormsModel::get_forms();
                foreach($forms as $form){
                    $selected = absint($form->id) == $config["form_id"] ? "selected='selected'" : "";
                    ?>
                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                    <?php
                }
                ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFYMLP::get_base_url() ?>/images/loading.gif" id="ymlp_wait" style="display: none;"/>
            </div>
            <div id="ymlp_field_group" valign="top" <?php echo empty($config["meta"]["contact_list_id"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                <div id="ymlp_field_container" valign="top" class="margin_vertical_10" >
                    <span class="left_header"><?php _e("Map Data Fields", "gravity-forms-ymlp"); ?> <?php gform_tooltip("ymlp_map_fields") ?><span class="howto"><?php _e('To add more Data Fields, go to Manage Contacts &rarr; Manage Data Fields in YMLP', 'gravity-forms-ymlp'); ?></span></span>

                    <div id="ymlp_field_list">
                    <?php
                    if(!empty($config["form_id"])){
	                    
                        //getting list of all YMLP merge variables for the selected contact list
                        if(empty($merge_vars))
                            $merge_vars = self::listMergeVars($list_id);

                        //getting field map UI
                        echo self::get_field_mapping($config, $config["form_id"], $merge_vars);

                        //getting list of selection fields to be used by the optin
                        $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                        $selection_fields = GFCommon::get_selection_fields($form_meta, $config["meta"]["optin_field_id"]);
                    }
                    ?>
                    </div>
                </div>

                <div id="ymlp_optin_container" valign="top" class="margin_vertical_10">
                    <label for="ymlp_optin" class="left_header"><?php _e("Opt-In Condition", "gravity-forms-ymlp"); ?> <?php gform_tooltip("ymlp_optin_condition") ?></label>
                    <div id="ymlp_optin">
                        <table>
                            <tr>
                                <td>
                                    <input type="checkbox" id="ymlp_optin_enable" name="ymlp_optin_enable" value="1" onclick="if(this.checked){jQuery('#ymlp_optin_condition_field_container').show('slow');} else{jQuery('#ymlp_optin_condition_field_container').hide('slow');}" <?php echo !empty($config["meta"]["optin_enabled"]) ? "checked='checked'" : ""?>/>
                                    <label for="ymlp_optin_enable"><?php _e("Enable", "gravity-forms-ymlp"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="ymlp_optin_condition_field_container" <?php echo empty($config["meta"]["optin_enabled"]) ? "style='display:none'" : ""?>>
                                        <div id="ymlp_optin_condition_fields" <?php echo empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("Export to YMLP if ", "gravity-forms-ymlp") ?>

                                            <select id="ymlp_optin_field_id" name="ymlp_optin_field_id" class='optin_select' onchange='jQuery("#ymlp_optin_value").html(GetFieldValues(jQuery(this).val(), "", 20));'><?php echo $selection_fields ?></select>
                                            <select id="ymlp_optin_operator" name="ymlp_optin_operator" />
                                                <option value="is" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "is") ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-ymlp") ?></option>
                                                <option value="isnot" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "isnot") ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-ymlp") ?></option>
                                            </select>
                                            <select id="ymlp_optin_value" name="ymlp_optin_value" class='optin_select'>
                                            </select>

                                        </div>
                                        <div id="ymlp_optin_condition_message" <?php echo !empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("To create an Opt-In condition, your form must have a drop down, checkbox or multiple choice field.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <script type="text/javascript">
                        <?php
                        if(!empty($config["form_id"])){
                            ?>
                            //creating Javascript form object
                            form = <?php echo GFCommon::json_encode($form_meta)?> ;

                            //initializing drop downs
                            jQuery(document).ready(function(){
                                var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
                                var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
                                SetOptin(selectedField, selectedValue);
                            });
                        <?php
                        }
                        ?>
                    </script>
                </div>

                <div id="ymlp_submit_container" class="margin_vertical_10">
                    <input type="submit" name="gf_ymlp_submit" value="<?php echo empty($id) ? __("Save Feed", "gravity-forms-ymlp") : __("Update Feed", "gravity-forms-ymlp"); ?>" class="button-primary"/>
                </div>
            </div>
        </form>
        </div>
		
		<script type="text/javascript">
		jQuery(document).ready(function($) { 
			$("#ymlp_check_all").live("change click load", function(e) {
				if(e.type == "load") {
					if($(".ymlp_checkboxes input").attr("checked")) {
						$(this).attr("checked", true);
					};
					return;
				}
				
				if($().prop) {
					$(".ymlp_checkboxes input").prop("checked", $(this).is(":checked"));
				} else {
					$(".ymlp_checkboxes input").attr("checked", $(this).is(":checked"));
				}
			}).trigger('load');
			
			<?php if(isset($_REQUEST['id']) && $_REQUEST['id'] == '0') { ?>
			$('#ymlp_field_list').live('load', function() {
				$('.ymlp_field_cell select').each(function() {
					var $select = $(this);
					if($().prop) {
						var label = $.trim($('label[for='+$(this).prop('name')+']').text());
					} else {
						var label = $.trim($('label[for='+$(this).attr('name')+']').text());
					}
					label = label.replace(' *', '');
					
					if($select.val() === '') {
						$('option', $select).each(function() {
							if($(this).text() === label) {
								if($().prop) {
									$('option:contains('+label+')', $select).prop('selected', true);
								} else {
									$('option:contains('+label+')', $select).prop('selected', true);
								}
							}
						});
					}
				});
			});
			<?php } ?>
		});
		</script>
		
		<script type="text/javascript">

            function SelectList(listId){
                if(listId){
                    jQuery("#ymlp_form_container").slideDown();
                    jQuery("#gf_ymlp_form").val("");
                }
                else{
                    jQuery("#ymlp_form_container").slideUp();
                    EndSelectForm("");
                }
            }

            function SelectForm(listId, formId){
                if(!formId){
                    jQuery("#ymlp_field_group").slideUp();
                    return;
                }

                jQuery("#ymlp_wait").show();
                jQuery("#ymlp_field_group").slideUp();

                var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_ymlp_form" );
                mysack.setVar( "gf_select_ymlp_form", "<?php echo wp_create_nonce("gf_select_ymlp_form") ?>" );
                mysack.setVar( "list_id", listId);
                mysack.setVar( "form_id", formId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#ymlp_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-ymlp") ?>' )};
                mysack.runAJAX();
                return true;
            }

            function SetOptin(selectedField, selectedValue){

                //load form fields
                jQuery("#ymlp_optin_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#ymlp_optin_field_id").val();

                if(optinConditionField){
                    jQuery("#ymlp_optin_condition_message").hide();
                    jQuery("#ymlp_optin_condition_fields").show();
                    jQuery("#ymlp_optin_value").html(GetFieldValues(optinConditionField, selectedValue, 20));
                }
                else{
                    jQuery("#ymlp_optin_condition_message").show();
                    jQuery("#ymlp_optin_condition_fields").hide();
                }
            }

            function EndSelectForm(fieldList, form_meta){
                //setting global form object
                form = form_meta;

                if(fieldList){

                    SetOptin("","");

                    jQuery("#ymlp_field_list").html(fieldList);
                    jQuery("#ymlp_field_group").slideDown();
					jQuery('#ymlp_field_list').trigger('load');
                }
                else{
                    jQuery("#ymlp_field_group").slideUp();
                    jQuery("#ymlp_field_list").html("");
                }
                jQuery("#ymlp_wait").hide();
            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field || !field.choices)
                    return "";

                var isAnySelected = false;

                for(var i=0; i<field.choices.length; i++){
                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                    var isSelected = fieldValue == selectedValue;
                    var selected = isSelected ? "selected='selected'" : "";
                    if(isSelected)
                        isAnySelected = true;

                    str += "<option value='" + fieldValue.replace("'", "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
                }

                if(!isAnySelected && selectedValue){
                    str += "<option value='" + selectedValue.replace("'", "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
                }

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;
                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if(inputType == "checkbox" || inputType == "radio" || inputType == "select"){
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

        </script>

        <?php

    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_ymlp");
        $wp_roles->add_cap("administrator", "gravityforms_ymlp_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_ymlp", "gravityforms_ymlp_uninstall"));
    }
    function r($content, $die =false) {
	    echo '<pre>'.print_r($content, true).'</pre>';
	    if($die) { die(); }
    }
    public static function disable_ymlp(){
        delete_option("gf_ymlp_settings");
    }

    public static function select_ymlp_form(){

        check_ajax_referer("gf_select_ymlp_form", "gf_select_ymlp_form");
        $form_id =  intval($_POST["form_id"]);
        list($list_id, $list_name) =  explode("|:|", $_POST["list_id"]);
        $setting_id =  0; //intval($_POST["ymlp_setting_id"]);

        $api = self::get_api();
        if(!empty($api->ErrorMessage))
            die("EndSelectForm();");
           
        //getting list of all YMLP merge variables for the selected contact list
        $merge_vars = self::listMergeVars($list_id);

        //getting configuration
        $config = GFYMLPData::get_feed($setting_id);

        //getting field map UI
        $str = self::get_field_mapping($config, $form_id, $merge_vars);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);
        //$fields = $form["fields"];
        die("EndSelectForm('" . str_replace("'", "\'", $str) . "', " . GFCommon::json_encode($form) . ");");
    }

    private static function get_field_mapping($config, $form_id, $merge_vars){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form_id);
        $form = RGFormsModel::get_form_meta($form_id);
       	
       	$customFields = array();
		
		$str = '';

        $str .= "<table cellpadding='0' cellspacing='0'><tr><td class='ymlp_col_heading'>" . __("List Fields", "gravity-forms-ymlp") . "</td><td class='ymlp_col_heading'>" . __("Form Fields", "gravity-forms-ymlp") . "</td></tr>";
        foreach($merge_vars as $var){
            $selected_field = (isset($config["meta"]) && isset($config["meta"]["field_map"]) && isset($config["meta"]["field_map"][$var["tag"]])) ? $config["meta"]["field_map"][$var["tag"]] : '';
            $required = $var["req"] == "Y" ? "<span class='gfield_required'>*</span>" : "";
            $error_class = $var["req"] == "Y" && empty($selected_field) && !empty($_POST["gf_ymlp_submit"]) ? " feeds_validation_error" : "";
            $str .= "<tr class='$error_class'><td class='ymlp_field_cell'><label for='ymlp_map_field_".$var['tag']."'>" . $var["name"]  . " $required</label></td><td class='ymlp_field_cell'>" . self::get_mapped_field_list($var["tag"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";
		
		return $str;
    }
        
	private function listMergeVars($blank) {
		
		$api = self::get_api();
		$lists = $api->FieldsGetList();
		foreach($lists as $list) {
			$output[] = array('tag' => $list['ID'], 'req' => false, 'name' => $list['FieldName']);
		}
		return $output;
		
	}
	
	
    public static function get_form_fields($form_id){
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        //Adding default fields
        array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravityformsmailchimp")));
        array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravityformsmailchimp")));
        array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravityformsmailchimp")));

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"]) && $field['type'] !== 'checkbox' && $field['type'] !== 'select'){

                    //If this is an address field, add full name to the list
                    if(RGFormsModel::get_input_type($field) == "address")
                        $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravityformsmailchimp") . ")");

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(empty($field["displayOnly"])){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    public static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "ymlp_map_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''>" . __("", "gravity-forms-ymlp") . "</option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = $field[1];

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }
    
    public static function get_mapped_field_checkbox($variable_name, $selected_field, $field){
        $field_name = "ymlp_map_field_" . $variable_name;
        $field_id = $field[0];
        $str =  "<input name='$field_name' id='$field_name' type='checkbox' value='$field_id'";
        $selected = $field_id == $selected_field ? " checked='checked'" : false;
        if($selected) {
        	$str .= $selected; 
        }
    
        $str .= " />";
        return $str;
    }

    public static function export($entry, $form){
        //Login to YMLP
        $api = self::get_api();
        if(!empty($api->ErrorMessage))
            return;

        //loading data class
        require_once(self::get_base_path() . "/data.php");

        //getting all active feeds
        $feeds = GFYMLPData::get_feed_by_form($form["id"], true);
        foreach($feeds as $feed){
            //only export if user has opted in
            if(self::is_optin($form, $feed))
                self::export_feed($entry, $form, $feed, $api);
        }
    }

    public static function export_feed($entry, $form, $feed, $api){
		
		$groupID = $feed["meta"]["contact_list_id"];
		
		foreach($form['fields'] as $field) {
	        if($field['type'] === 'email') {
	        	$email_field_id = $field['id'];
	        	$email = $entry[$field['id']];
	        }
        }
        
        
        foreach($feed["meta"]["field_map"] as $var_tag => $field_id) {

            $field = RGFormsModel::get_field($form, $field_id);
            
            if($field['id'] === $email_field_id) { continue; }
            
            $value = RGFormsModel::get_lead_field_value($entry, $field);

            if(!is_array($value)) {
            	$field_value = GFCommon::get_lead_field_display($field, $value);
        	} else {
        		$field_value = implode(', ', $value);
        	}
        	
        	$merge_vars['Field'.$var_tag] = strip_tags($field_value);
        }

		$retval = $api->ContactsAdd($email, $merge_vars, $groupID);
		
		self::show_admin_messages($retval, $merge_vars);
    }
    
    private static function show_admin_messages($apiReturn, $data = array()) {
	    if(current_user_can('manage_options')) {
	    	
	    	$settings = get_option("gf_ymlp_settings");
	    	if(empty($settings['debug'])) { return; }
	    	
	    	echo '<div style="border:1px solid #ccc; background:white; padding:10px;">
	    		<h3>Admin-Only Message</h3>';
	    	echo '<h4>'.$message.'</h4>';
	    	if(!empty($apiReturn['Output'])) { echo '<p>Submitted Form Response: '.$apiReturn['Output'].'</p>'; }
	    	echo '<h4>Submitted form data:</h4>';
	    	self::r($data);
	    	echo '</div>';
	    }
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFYMLP::has_access("gravityforms_ymlp_uninstall"))
            die(__("You don't have adequate permission to uninstall YMLP Add-On.", "gravity-forms-ymlp"));

        //droping all tables
        GFYMLPData::drop_tables();

        //removing options
        delete_option("gf_ymlp_settings");
        delete_option("gf_ymlp_version");

        //Deactivating plugin
        $plugin = "gravity-forms-ymlp/ymlp.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    public static function is_optin($form, $settings){
        $config = $settings["meta"];
        $operator = $config["optin_operator"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);
        $field_value = RGFormsModel::get_field_value($field, array());
        $is_value_match = is_array($field_value) ? in_array($config["optin_value"], $field_value) : $field_value == $config["optin_value"];

        return  !$config["optin_enabled"] || empty($field) || ($operator == "is" && $is_value_match) || ($operator == "isnot" && !$is_value_match);
    }


    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }
    
    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

}
