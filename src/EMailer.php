<?php
namespace SOS;
use \TymFrontiers\Data,
    \TymFrontiers\Generic,
    \TymFrontiers\MultiForm,
    \TymFrontiers\MySQLDatabase,
    \TymFrontiers\File,
    \TymFrontiers\InstanceError,
    \Mailgun\Mailgun;

class EMailer{
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key='id';
  protected static $_db_name = MYSQL_LOG_DB;
  protected static $_table_name="email_outbox";
	protected static $_db_fields = [
    "id",
    "priority",
    "qid",
    "transport",
    "gateway",
    "domain",
    "batch",
    "has_attachment",
    "status",
    "subject",
    "msg_text",
    "msg_html",
    "sender",
    "receiver",
    "cc",
    "bcc",
    "headers",
    "_created",
    "_updated"
  ];
  protected static $_prop_type = [];
  protected static $_prop_size = [];

  const PREFIX = "SOSEML.";
  const SURFIX = ".SOSEML";

  private $id;

  public $priority=5;
  public $qid;
  public $transport = "API";
  public $gateway = "MAILGUN";
  public $domain;
  public $batch;
  public $has_attachment = false;
  public $status = "Q";
  public $subject;
  public $msg_text;
  public $msg_html;
  public $sender;
  public $receiver;
  public $cc;
  public $bcc;
  public $header = NULL;

  protected $_created;
  protected $_updated;

  public $errors = [];

  function __construct( $prop = [], int $priority=5){
    global $mailgun_api_domain;
    if( \is_array($prop) && !empty($prop)){
      $this->init($prop);
    }
    $this->batch = !empty($this->batch) ? $this->batch : self::PREFIX . \time();
    $this->domain = !empty($this->domain) ? $this->domain : $mailgun_api_domain;
    $this->priority = $priority;
  }
  public function init( array $prop ){
    foreach($prop as $prop=>$val){
      if( \property_exists($this,$prop) ) $this->$prop = $val;
    }
  }
  public function addHeader (array $header) {
    $prep_headers = [];
    foreach ($header as $key=>$value) {
      if (!\is_int($key) && !empty($value)) {
        $prep_headers[] = "h:{$key}|:{$value}";
      }
    }
    if (!empty($prep_headers)) {
      $this->headers = \implode ("|;",$prep_headers);
      return true;
    }
    return false;
  }
  public function queue(int $priority=5){
    if( empty($this->id) ){
      $this->status = "Q";
      if(
        !empty($this->subject)
        && ( !empty($this->msg_text) || !empty($this->msg_html) )
        && !empty($this->sender)
        && !empty($this->receiver)
      ){
        $this->priority = $priority;
        return $this->_create();
      }
    }
    return false;
  }
  public function send(){
    if( ( empty($this->id) && $this->queue() ) || !empty($this->id)  ){
      if( \in_array($this->status,['Q','D']) ){
        global $mailgun_api_domain, $mailgun_api_key, $database, $session;
        if( $this->gateway == 'MAILGUN' ){
          if( $this->transport == 'API' ){
            $attachments = [];
            if( (bool)$this->has_attachment ){
              $log_db = MYSQL_LOG_DB;
              $files = (new File)
                ->findBySql("SELECT *
                            FROM :db:.:tbl:
                            WHERE id IN (
                              SELECT fid
                              FROM `{$log_db}`.`email_outbox_attachment`
                              WHERE ebatch = '{$database->escapeValue($this->batch)}'
                            )");
                if( $files ){
                  foreach($files as $file){
                    $attachments[] = [
                      "filePath" => $file->fullPath(),
                      "filename" => $file->nice_name . '.' . Generic::fileExt($file->fullPath()),
                    ];
                  }
                }
              }

              $mgClient = Mailgun::create($mailgun_api_key);
              $msg_r = [
                'from' => $this->sender,
                'to' => $this->receiver,
                'subject' => $this->subject
              ];
              if( !empty($this->msg_text) ) $msg_r['text'] = $this->msg_text;
              if( !empty($this->msg_html) ) $msg_r['html'] = $this->msg_html;
              if( !empty($this->cc) ) $msg_r['cc'] = \str_replace(';',',',$this->cc);
              if( !empty($this->bcc) ) $msg_r['bcc'] = \str_replace(';',',',$this->bcc);
              if (!empty($this->headers)) {
                foreach (\explode("|;",$this->headers) as $header) {
                  $header_r = \explode("|:",$header);
                  if (\count($header_r) > 1) {
                    $msg_r[$header_r[0]] = $header_r[1];
                  }
                }
              }
              if (!empty($attachments)) {
                $msg_r["attachment"] = $attachments;
              }
              try {
                // die( var_dump($attachments) );
                $result = $mgClient->messages()->send($this->domain,$msg_r);
                if(
                  \is_object($result) &&
                  !empty($result->getId()) &&
                  \strpos($result->getId(), $mailgun_api_domain) !== false
                ){
                  // reconnect
                  $conn = new MySQLDatabase(MYSQL_SERVER, MYSQL_DEVELOPER_USERNAME, MYSQL_DEVELOPER_PASS);
                  $log_db = MYSQL_LOG_DB;
                  if (!$conn->query("UPDATE `{$log_db}`.`email_outbox` SET `status` = 'S', `qid` = '{$conn->escapeValue($result->getId())}' WHERE id= {$this->id} LIMIT 1")) {
                    $this->errors['send'][] = [1, 256,"Failed to update record",__FILE__,__LINE__];
                  } else {
                    $conn->closeConnection();
                    return true;
                  }
                }else{
                  // echo "Failed to send message. \r\n";
                  $this->errors['send'][] = [3,256,"Failed to send [Email] message",__FILE__,__LINE__];
                }
              } catch (\Exception $e) {
                $this->errors['send'][] = [3,256,$e->getMessage(),__FILE__,__LINE__];
              }
            }
          }
      }else{
        $this->errors['send'][] = [0,256,"Message was already sent.",__FILE__,__LINE__];
      }
    }
    return false;
  }
  public function id(){ return $this->id; }
  public function load( int $id){
    return self::findById($id);
  }
  public final function attachFile(array $ids) {
    global $database;
    $log_db = MYSQL_LOG_DB;
    if ($ids && !empty($this->batch)) {
      $query = "INSERT INTO `{$log_db}`.`email_outbox_attachment` (`ebatch`, `fid`) VALUES ";
      $val_rr = [];
      foreach ($ids as $id) {
        $id = (int)$id;
        if (\is_int($id) && $id > 0)  $val_rr[] = "('{$this->batch}',{$id})";
      }
      if (empty($val_rr)) throw new \Exception("[ids] is not array of file ids", 1);
      $query .= \implode(",",$val_rr);
      if (!$database->query($query)) throw new \Exception("Attaching file(s) failed, try again.", 1);
      $this->has_attachment = true;
      return true;
    }
  }
}
