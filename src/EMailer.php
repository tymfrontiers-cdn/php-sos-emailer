<?php
namespace SOS;
use \TymFrontiers\Data,
    \TymFrontiers\Generic,
    \TymFrontiers\MultiForm,
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

  const PREFIX = "WBZEML.";
  const SURFIX = ".WBZEML";

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
    global $email_domain;
    if( \is_array($prop) && !empty($prop)){
      $this->init($prop);
    }
    $this->batch = !empty($this->batch) ? $this->batch : self::PREFIX . \time();
    $this->domain = !empty($this->domain) ? $this->domain : $email_domain;
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
        global $email_domain,$email_gateways,$email_domain_key,$email_transports,$db;
        if( $this->gateway == 'MAILGUN' ){
          if( $this->transport == 'API' ){
            $attachments = [];
            if( (bool)$this->has_attachment ){
              $files = File::findBySql("SELECT * FROM " . MYSQL_LOG_DB .".file WHERE id IN (
                SELECT fid FROM ".MYSQL_LOG_DB.".email_outbox_attachment WHERE ebatch = '{$db->escapeValue($this->batch)}'
                )");
                if( $files ){
                  foreach($files as $file){
                    $attachments[] = [
                      "remoteName" => $file->nice_name . '.' . Generic::fileExt($file->fullPath()),
                      "filePath" => $file->fullPath()
                    ];
                  }
                }
              }

              $mgClient = new Mailgun($email_domain_key);
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
              try {
                // die( var_dump($attachments[$batch]) );
                $result = !empty($attachments)
                  ? $mgClient->sendMessage($this->domain,$msg_r,['attachment'=>$attachments])
                  : $mgClient->sendMessage($this->domain,$msg_r);

                if(
                  \is_object($result) &&
                  !empty($result->http_response_body->id) &&
                  \strpos($result->http_response_body->id, $this->domain) !== false
                ){
                  $this->status = 'S';
                  $this->qid = $result->http_response_body->id;
                  return $this->_update();
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
}
