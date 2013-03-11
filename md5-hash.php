<?php
/*
Plugin Name: MD5 Hasher
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
    
    /**
     * Setup hooks and load settings
     * @return void
     */
    public function __construct(){
        add_action('Md5_Checksum', array($this, 'run_hash_check'));
        add_action('wp', array($this, 'schedule_cron'));

        register_activation_hook(__FILE__, array($this, 'run_hash_check'));
        register_deactivation_hook(__FILE__, array($this, 'unshedule_cron'));
    }

    /**
     * Setup wp-cron to check weekly
     * @return void
     */
    public function schedule_cron() {
        if ( !wp_next_scheduled( 'Md5_Checksum' ) ) {
            wp_schedule_event( time(), 'hourly', 'Md5_Checksum');
        }
    }

    /**
     * Remove wp-cron schedule
     * @return void
     */
    private function unshedule_cron(){
        wp_unschedule_event( time(), 'Md5_Checksum');
    }

    /**
     * Check Md5 checksum in wordpress directory
     * @return void
     */
    public function run_hash_check() {

        // load list of file hashes
        $this->read_hash_file();

        // read all files hashes
        $this->read_directory();

        // save updated md5 hashes
        $this->save_hash_file();
        
        // log changes
        if(!empty($this->md5_checksums)){
           
            $this->save_log_file();
            $this->emailChanges();
        }
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
            $this->md5_gen_output[$dir_file] = array(
                'md5' => md5_file($dir_file),
                'filename' => $obj->getFilename(),
                'real_path' => $dir_file
            );

            if(!isset($this->md5_gen_old[$dir_file]->md5)){
                // new file
                $this->md5_changed_output[$dir_file] = array(
                    'md5' => md5_file($dir_file),
                    'filename' => $obj->getFilename(),
                    'real_path' => $dir_file,
                    'modified' => 'new'
                );
            }else if($this->md5_gen_old[$dir_file]->md5 !== $this->md5_gen_output[$dir_file]['md5']){
                // modified file
                $this->md5_changed_output[$dir_file] = array(
                    'md5' => md5_file($dir_file),
                    'filename' => $obj->getFilename(),
                    'real_path' => $dir_file,
                    'modified' => 'edited'
                ); 
            }
        }
    }
    
    /**
     * Save Changes to file
     * @return void
     */
    private function save_log_file(){
        if(is_file(MD5_HASHER_DIR . $this->file_change)){
            $fh = fopen(MD5_HASHER_DIR.$this->file_change, 'a');
            fwrite($fh, date('d/m/Y H:i:s')." Changed Files(".count($this->md5_changed_output)."):\n\n");
            foreach($this->md5_changed_output as $k => $v){
                fwrite($fh, $v['filename'].' => '.$v['modified']. "\n");
            }
            fwrite($fh, "\n");
        }else{
            $fh = fopen(MD5_HASHER_DIR.$this->file_change, 'x');
        }
        fclose($fh); 
    }

    /**
     * Save new hashes to file
     * @return void
     */
    private function save_hash_file(){
        $fh = fopen(MD5_HASHER_DIR.$this->file_check, 'w');
        fwrite($fh, json_encode($this->md5_gen_output));
        fclose($fh);
    }

    /**
     * Load hashes from file
     * @return void
     */
    private function read_hash_file(){
        if(!is_file(MD5_HASHER_DIR . $this->file_check)){
            // create empty file if none exits
            $fh = fopen(MD5_HASHER_DIR . $this->file_check, 'x');
        }else{
            $fh = fopen(MD5_HASHER_DIR . $this->file_check, 'r');
            $this->md5_gen_old = (Array)json_decode(fread($fh, filesize(MD5_HASHER_DIR.$this->file_check)));
        }
        fclose($fh);
    }

    /**
     * Generate Email Message to be sent to administration
     * @return void
     */
    private function emailChanges(){
        $message = 'File Changes:'."\n";
        foreach($this->md5_changed_output as $k => $v){
            $message .=  $v['filename'].' => '.$v['modified']. "\n";
        }
        
        $emails = $this->getAdminEmails();
        if($emails){
            wp_mail( $emails, 'Website Changes', $message);
        }else{
            wp_mail( get_bloginfo('admin_email'), 'Website Changes', $message);
        }
    }

    /**
     * Get list of administrator email addresses
     * @param  array $ids specific admin ids
     * @return array      admin email addresses
     */
    private function getAdminEmails($ids = null){
        $emails = array();
        $args = array('role' => 'Administrator');

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
}

$MD5_Hasher = new MD5_Hasher();