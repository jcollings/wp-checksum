<?php
/*
Plugin Name: WP Checksum Generator
Plugin URI: http://www.jclabs.co.uk
Description: Security Process to show latest modified/created files, run as frequently as deemed fit, an email is sent automatically with the results.  
Author: James Collings
Version: 0.0.1
Author URI: http://www.jclabs.co.uk
*/

define('MD5_HASHER_DIR', plugin_dir_path( __FILE__ ));

class Md5_Hasher{

    private $file_check = 'md5_checksums.txt';
    private $file_change = 'md5_changes.txt';
    private $md5_gen_old = array();
    private $md5_gen_output = array();
    private $md5_changed_output = array();
    private $settings_optgroup = 'wp-checksum-generator';
    private $email = true;
    
    /**
     * Setup hooks and load settings
     * @return void
     */
    public function __construct(){
        add_action('md5_hasher_check_dir', array($this, 'hash_check'));
        add_action( 'admin_menu', array($this, 'settings_menu' ));

        add_filter( 'cron_schedules', array($this, 'cron_add_schedules'));
        add_filter('plugin_action_links_'.plugin_basename(__FILE__), array($this, 'settings_link'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'unshedule_cron'));

        if(isset($_GET['page']) && isset($_GET['check']) && $_GET['page'] == 'checksum-generator' && $_GET['check'] == 1){
            $this->run_hash_check();
        }
    }

    /**
     * Plugin Activation
     * @return void
     */
    public function activate(){
        $this->email = false;

        $this->schedule_cron();
        $this->hash_check();

        $message = 'Keep this checksum of the server safe, it can be used to view all changes made to the website since the plugin was activated';
        wp_mail( get_bloginfo('admin_email'), 'WP-Checksum Activation: '.get_bloginfo('site_title'), $message, '', array(MD5_HASHER_DIR.$this->file_check));
    }

    /**
     * Setup wp-cron to check weekly
     * @return void
     */
    public function schedule_cron($rate = 'md5_hash_weekly') {
        if ( !wp_next_scheduled( 'md5_hasher_check_dir' ) ) {
            wp_schedule_event( time(), $rate, 'md5_hasher_check_dir');
        }
    }

    /**
     * Remove wp-cron schedule
     * @return void
     */
    public function unshedule_cron(){
        wp_clear_scheduled_hook('md5_hasher_check_dir');
    }

    /**
     * Check Md5 checksum in wordpress directory
     * @return void
     */
    public function run_hash_check() {
        add_action('plugins_loaded', array($this, 'hash_check' ));
    }
    public function hash_check(){
         // load list of file hashes
        $this->read_hash_file();

        // read all files hashes
        $this->read_directory();

        // save updated md5 hashes
        $this->save_hash_file();
        
        // log changes
        $this->save_log_file();

        // email results
        if($this->email)
            $this->emailChanges();
    }

    /**
     * Read recursivly through the wordpress directory to check for changes
     * @return void
     */
    private function read_directory(){
        $rdi = new RecursiveDirectoryIterator(ABSPATH);
        $rii = new RecursiveIteratorIterator($rdi);
        foreach($rii as $name => $obj){
            $dir_file = $obj->getRealPath(); 

            if( strcmp(str_replace("\\", "/", $dir_file),str_replace("\\", "/", MD5_HASHER_DIR.$this->file_check)) <> 0 
                && strcmp(str_replace("\\", "/", $dir_file), str_replace("\\", "/", MD5_HASHER_DIR.$this->file_change)) <> 0){

                if(is_readable($dir_file)){
                    $hash_key = @md5_file($dir_file);
                    $this->md5_gen_output[$dir_file] = array(
                        'md5' => $hash_key,
                        'filename' => $obj->getFilename(),
                        'real_path' => $dir_file
                    );

                    $file_ext = substr(strrchr($obj->getFilename(),'.'),1);

                    if(!isset($this->md5_gen_old[$dir_file]->md5)){
                        // new file
                        $this->md5_changed_output[$dir_file] = array(
                            'md5' => $hash_key,
                            'ext' => $file_ext,
                            'filename' => $obj->getFilename(),
                            'real_path' => $dir_file,
                            'modified' => 'new'
                        );
                    }else if($this->md5_gen_old[$dir_file]->md5 !== $this->md5_gen_output[$dir_file]['md5']){
                        // modified file
                        $this->md5_changed_output[$dir_file] = array(
                            'md5' => $hash_key,
                            'ext' => $file_ext,
                            'filename' => $obj->getFilename(),
                            'real_path' => $dir_file,
                            'modified' => 'edited'
                        ); 
                    }
                }
            }
        }
    }

    private function order_changed_log(){
        $unsorted = $this->md5_changed_output;
        $sorted = array();

        foreach($unsorted as $log){
            $sorted[$log['ext']][] = $log;
        }
        
        return $sorted;
    }
    
    /**
     * Save Changes to file
     * @return Boolean
     */
    private function save_log_file(){

        $file = MD5_HASHER_DIR . $this->file_change;
        if(is_file($file) && is_writable($file)){
            $fh = fopen($file, 'w');
            fwrite($fh, date('d/m/Y H:i:s')." Changed Files(".count($this->md5_changed_output)."):\n");

            $changes = $this->order_changed_log();
            foreach($changes as $ext => $log_type){
                fwrite($fh, "\nFile Type (".$ext."): \n");
                foreach($log_type as $k => $v){
                    fwrite($fh, $v['real_path'].' => '.$v['modified']. "\n");
                }
            }

            fwrite($fh, "\n");
            fclose($fh);
            return true;
        }

        return false;
    }

    /**
     * Save new hashes to file
     * @return Boolean
     */
    private function save_hash_file(){

        $file = MD5_HASHER_DIR.$this->file_check;
        if(is_file($file) && is_writable($file)){
            $fh = fopen($file, 'w');
            fwrite($fh, json_encode($this->md5_gen_output));
            fclose($fh);
            return true;
        }

        return false;
    }

    /**
     * Load hashes from file
     * @return void
     */
    private function read_hash_file(){

        $file = MD5_HASHER_DIR . $this->file_check;

        if(is_file($file) && is_readable($file)){
            $fh = fopen(MD5_HASHER_DIR . $this->file_check, 'r');

            if(filesize(MD5_HASHER_DIR.$this->file_check) > 0)
                $this->md5_gen_old = (Array)json_decode(fread($fh, filesize(MD5_HASHER_DIR.$this->file_check)));

            fclose($fh);
        }
    }

    /**
     * Generate Email Message to be sent to administration
     * @return void
     */
    private function emailChanges(){
        $emails = $this->getAdminEmails(get_option('notification_users'));
        $custom_email = get_option('notification_email');

        if(isset($custom_email['email_add']) && !empty($custom_email['email_add']))
            $emails[] = $custom_email['email_add'];

        if(!$emails){
            $emails = get_bloginfo('admin_email');
        }

        $message = " Changed Files(".count($this->md5_changed_output)."):\n";
        $changes = $this->order_changed_log();
        $files = 0;
        foreach($changes as $ext => $log_type){
            $message .=  "\nFile Type (".$ext."): \n";
            foreach($log_type as $k => $v){
                $files++;
                $message .=  $v['real_path'].' => '.$v['modified']. "\n";
            }
        }

        wp_mail( $emails, 'WP-Checksum File Modifications: '.$files, $message, '', array(MD5_HASHER_DIR.$this->file_change));
    }

    /**
     * Get list of administrator email addresses
     * @param  array $ids specific admin ids
     * @return array      admin email addresses
     */
    private function getAdminEmails($ids = null){
        $emails = array();
        $args = array('role' => 'Administrator');

        if(is_null($ids) || empty($ids))
            return false;

        if(is_array($ids) && !empty($ids)){
            $args['include'] = $ids;
        }

        $wp_user_query = new WP_User_Query($args);
        $admins = $wp_user_query->get_results();

        if(!empty($admins)){
            foreach($admins as $admin){
                $emails[] = $admin->user_email;
            }
        }

        if(empty($emails))
            return false;

        return $emails;
    }

    /**
     * Add weekly cron schedule
     * @return array
     */
    public function cron_add_schedules( $schedules ) {
        $schedules['md5_hash_weekly'] = array(
            'interval' => 604800,
            'display' => __( 'Once Weekly' )
        );
        return $schedules;
    }

    /**
     * Add settings link to plugins table
     * @param  array $args links 
     * @return arrary
     */
    function settings_link($args){
        array_unshift($args, '<a href="tools.php?page=checksum-generator">Settings</a>');
        return $args;
    }

    /**
     * Create plugin options page under tools
     * @return void
     */
    public function settings_menu(){
        // add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function = '')
        add_submenu_page('tools.php','Checksum Generator', 'MD5 Checksums', 'manage_options', 'checksum-generator', array($this, 'theme_options_page'));

        //call register settings function
        add_action( 'admin_init', array($this, 'register_settings' ));
    }

    /**
     * Output settings page
     * @return void
     */
    public function theme_options_page(){
        $tabs = array(
            1 => 'checksum_scanner',
            2 => 'tab_notifications'
        );
        ?>
        <div class="wrap">
            <div id="icon-options-general" class="icon32"><br></div><h2>Checksum Generator<a href="?page=checksum-generator&check=1" class="add-new-h2">Run Now</a></h2>

            <?php $tab = isset($_GET['tab']) ? $_GET['tab'] : 1; ?>

            <?php if((isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true' && $tab == 1)
                || !isset($_GET['tab']) && isset($_GET['check'])){
                $tab = 3; 
            } ?>

            <p><strong>Next Scheduled Check:</strong> <?php echo date('H:i:s \o\n \t\h\e d/m/Y', wp_next_scheduled( 'md5_hasher_check_dir')); ?></p>

            <h3 class="nav-tab-wrapper">
                <a href="?page=checksum-generator&tab=1" class="nav-tab <?php if($tab == 1): ?>nav-tab-active<?php endif; ?>">Checksum Scanner</a>
                <a href="?page=checksum-generator&tab=2" class="nav-tab <?php if($tab == 2): ?>nav-tab-active<?php endif; ?>">Settings</a>
                <a href="?page=checksum-generator&tab=3" class="nav-tab <?php if($tab == 3): ?>nav-tab-active<?php endif; ?>">Scan Results</a>
            </h3>
        
            <?php if($tab == 1 || $tab == 2): ?>
            <form action="options.php" method="post" enctype="multipart/form-data">  
                <?php  
                settings_fields($this->settings_optgroup);
                // do_settings_sections(__FILE__);  
                do_settings_sections($tabs[$tab])
                ?>  
                <p class="submit">  
                    <input name="Submit" type="submit" class="button-primary" value="<?php esc_attr_e('Save Changes'); ?>" />  
                </p>  
            </form> 
            <?php endif; ?>

            <?php
            if($tab == 3){
                echo '<pre>';
                echo file_get_contents(MD5_HASHER_DIR . $this->file_change);
                echo '</pre>';
            }
            ?>

        </div>
        <?php
    }

    /**
     * Register Plugin Settings
     * @return void
     */
    function register_settings()
    {
        // register_setting($option_group, $option_name, $sanitize_callback = '')
        register_setting($this->settings_optgroup, 'notification_email');
        register_setting($this->settings_optgroup, 'notification_users');
        register_setting($this->settings_optgroup, 'checksum_file', array($this, 'save_settings'));
        register_setting($this->settings_optgroup, 'notification_time', array($this, 'save_settings'));

        // add_settings_section($id, $title, $callback, $page)
        add_settings_section('notifications', 'Notification Settings', array($this, 'section_notification'), 'tab_notifications');
        add_settings_section('scanner', 'Scanner', array($this, 'section_scanner'), 'checksum_scanner');


        // add_settings_field($id, $title, $callback, $page, $section = 'default', $args = array)
        add_settings_field('email_add', 'Email Address', array($this, 'field_callback'), 'tab_notifications', 'notifications', array(
            'type' => 'text',
            'field_id' => 'email_add',
            'section_id' => 'notifications',
            'setting_id' => 'notification_email'
        ));

        $wp_user_query = new WP_User_Query(array('role' => 'Administrator'));
        $users = array();
        $admins = $wp_user_query->get_results();
        if(!empty($admins)){
            foreach($admins as $admin){
                $users[$admin->ID] = $admin->user_nicename;
            }
        }

        add_settings_field('email_users', 'Admin Accounts', array($this, 'field_callback'), 'tab_notifications', 'notifications', array(
            'type' => 'select',
            'choices' => $users,
            'multiple' => true,
            'field_id' => 'email_users',
            'section_id' => 'notifications',
            'setting_id' => 'notification_users'
        ));
        add_settings_field('frequency', 'Schedule Frequency', array($this, 'field_callback'), 'tab_notifications', 'notifications', array(
            'type' => 'select',
            'choices' => array('hourly' => 'Hourly','daily' => 'Daily','weekly' => 'Weekly'),
            'default' => 'weekly',
            'field_id' => 'frequency',
            'section_id' => 'notifications',
            'setting_id' => 'notification_time'
        ));
        add_settings_field('file_upload', 'Checksum File', array($this, 'field_callback'), 'checksum_scanner', 'scanner', array(
            'type' => 'upload',
            'field_id' => 'file_upload',
            'section_id' => 'scanner',
            'setting_id' => 'checksum_file'
        ));
    }

    /**
     * Validate Save Settings
     * @param  array
     * @return array
     */
    public function save_settings($args){
     
        if(isset($args['frequency']) && is_array($args['frequency'])){

            $value = $args['frequency'];
            $default = get_option('notification_time');

            if($value !== $default['frequency']){
                
                $this->unshedule_cron();
                switch($value[0]){
                    case 'hourly':
                        $this->schedule_cron('hourly');
                    break;
                    case 'daily':
                        $this->schedule_cron('daily');
                    break;
                    case 'weekly':
                    default:
                        $this->schedule_cron();
                    break;
                }
                
            }     
        }elseif(isset($_FILES['checksum_file'])){

            // load uploaded files
            $file = file_get_contents($_FILES['checksum_file']['tmp_name']['file_upload']);
            
            // load compare file
            $this->md5_gen_old = (Array)json_decode($file);

            // read directory
            $this->read_directory();

            $this->save_log_file();
        }
        
        return $args;
    }

    /**
     * notification section description
     * @return void
     */
    public function section_notification()
    {
        ?>
        <p>Set the emails you wish to recieve notifications</p>
        <?php
    }

    /**
     * scanner section description
     * @return void
     */
    public function section_scanner()
    {
        ?>
        <p>Upload a previously generated checksum file, to compare against the websites files.</p>
        <?php
    }

    /**
     * Generate the output for all settings fields
     * @param  array $args options for each field
     * @return void
     */
    public function field_callback($args)
    {
        $multiple = false;
        extract($args);
        $options = get_option($setting_id);
        switch($args['type'])
        {
            case 'text':
            {
                $value = isset($options[$field_id]) ? $options[$field_id] : '';
                ?>
                <input class='text' type='text' id='<?php echo $setting_id; ?>' name='<?php echo $setting_id; ?>[<?php echo $field_id; ?>]' value='<?php echo $value; ?>' />
                <?php
                break;
            }
            case 'select':
            {
                    ?>
                    <select id="<?php echo $setting_id; ?>" name="<?php echo $setting_id; ?>[<?php echo $field_id; ?>][]" <?php if($multiple === true): ?>multiple<?php endif; ?>>
                    <?php
                    foreach($choices as $id => $name):?>
                        <?php if(isset($options[$field_id]) && is_array($options[$field_id]) && in_array($id,$options[$field_id])): ?>
                        <option value="<?php echo $id; ?>" selected="selected"><?php echo $name; ?></option>
                        <?php else: ?>
                        <option value="<?php echo $id; ?>"><?php echo $name; ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </select>
                    <?php
                break;
            }
            case 'upload':
            {
                ?>
                <input class='file' type='file' id='<?php echo $setting_id; ?>' name='<?php echo $setting_id; ?>[<?php echo $field_id; ?>]'  />
                <?php
                break;
            }
        }
    }
}

$MD5_Hasher = new MD5_Hasher();