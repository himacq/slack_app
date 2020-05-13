<?php namespace App;

class Slack {

    protected $token;
    protected $app;

    /**
     * Slack constructor.
     * @param $app the name of the slack application
     * @param $token the Bot User OAuth Access Token
     */
    public function __construct($app,$token)
    {
        $this->app = $app;
        $this->token = $token;
    }

    /**
     * @param $message
     * @param $channel channel ID or user ID
     * @return bool|string
     */
    public function send($message, $channel)
    {
        $data = [
            "token" => $this->token,
            "channel" => $channel,
            "text" => $message,
            "username" => $this->app,
        ];

        return $this->post_curl("https://slack.com/api/chat.postMessage",$data);
    }

    /**
     * return a list of users in the workspace from slack application
     * @return bool|string
     */
    public function slack_users()
    {
        $list =  $this->get_curl("https://slack.com/api/users.list?token=".$this->token);
        $list_array = json_decode($list);

        $result = array();
        foreach($list_array->members as $member){
            if($member->deleted || $member->is_bot || $member->is_app_user)
                continue;

            $element = [
                'id' => $member->id,
                'name' =>$member->real_name,
                'image' =>$member->profile->image_72,
            ];
            $result[] = $element;
        }

        return json_encode($result);
    }

    /**
     * curl custom post
     * @param $url
     * @param $data
     * @return bool|string
     */
    private function post_curl($url, $data)
    {
        $ch = curl_init($url);
        $data = http_build_query($data);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    /**
     * curl custom get
     * @param $url
     * @return bool|string
     */
    private function get_curl($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    /**
     * handle event from slack call back to store messages and send extra questions
     * @param $eventArray
     * @return false|int
     */

    public function handel($eventArray)
    {
        // create a json file for each channel
        $folder =  __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR;
        $user_file = $folder.$eventArray['event']['channel'].'.json';

        if(!file_exists($user_file)) {

             $this->create_user_file($user_file);

            $questions = json_decode($this->get_questions());
                $previous_content_array = array();

        }
        else{
            $previous_content = file_get_contents($user_file);
            $previous_content_array = get_object_vars(json_decode($previous_content));


            if(isset($previous_content_array['questions']))
                $questions = $previous_content_array['questions'];

            else
                $questions = json_decode($this->get_questions());

            if(isset($eventArray['event']['bot_id']) && $previous_content_array['questions'][0]=="Done")
                $questions = json_decode($this->get_questions());


        }

        /// adding profile section to the conversation file
        if(!isset($previous_content_array['profile']) && !isset($eventArray['event']['bot_id']) ){
            $previous_content_array['profile'] = [
                'user_id'=>$eventArray['event']['user'],
                'name'=> $this->get_user_data('name' , $eventArray['event']['user']),
                'avatar'=>$this->get_user_data('image' , $eventArray['event']['user'])
            ];
        }


        // check if sender is  bot
        //put questions -1
        if(isset($eventArray['event']['bot_id']))
            unset($questions[0]);

        // if all questions sent set question directive to done
        if(empty($questions))
            $questions[0] = "Done";

        $questions = array_values($questions);

        $previous_content_array['questions'] = $questions;

        $content = array();
        if(isset($eventArray['event']['bot_id'])){
            $content = [
                'bot' => $eventArray['event']['bot_profile']['name'],
                'avatar' => $eventArray['event']['bot_profile']['icons']['image_72'],
                'channel' => $eventArray['event']['channel'],
                'text' => $eventArray['event']['text']
            ];
        }
        else {

            //handel messages with attachments
           if(isset($eventArray['event']['files'])){

                $files = array();
               $content_file = array();
                foreach($eventArray['event']['files'] as $file) {
                    $content_file = [
                        'name' =>  $file['name'],
                        'mimetype' => $file['mimetype'],
                        'url_private' => $file['url_private'],
                        'url_private_download'=> $file['url_private_download'],
                        'permalink'=>$file['permalink']
                    ];

                    if(isset($file['thumb_480']))
                        $content_file['thumb'] = $file['thumb_480'];

                    $files[] = $content_file;
                }

               $content = [
                   'type' => 'files',
                   'user_id' => $eventArray['event']['user'],
                   'channel' => $eventArray['event']['channel'],
                   'text' => $eventArray['event']['text'],
                   'files' => $files
               ];

                }

            // handel text message
          else if(isset($eventArray['event']['blocks'])) {
                $content = [
                    'type'=>'text',
                    'user_id' => $eventArray['event']['user'],
                    'channel' => $eventArray['event']['channel'],
                    'text' => $eventArray['event']['text']
                ];
            }

        }

        if(is_null($previous_content_array['conversations'])){
            $previous_content_array['conversations'] = array($content);
        }
        else {
            $conversations = $previous_content_array['conversations'];
            $conversations[] = $content;
            $previous_content_array['conversations'] = $conversations;
        }

        $data = json_encode($previous_content_array);
         file_put_contents($user_file,$data);

        // if user replies send next question
        if(!isset($eventArray['event']['bot_id'])) {
            return $this->send($questions[0]->question_text, $eventArray['event']['channel']);
        }

    }

    /**
     * @return mixed
     * get questions' list
     */

    public function get_questions()
    {
        $questions_file = __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'questions.json';

        if(!file_exists($questions_file))
            $fh = fopen($questions_file, 'w') or die("Can't create file");

        return file_get_contents($questions_file);
    }

    /**
     * @return false|int
     * refresh users' list from slack server
     */
    public function refresh_users_list()
    {
        $user_file =  __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'users.json';

        if(!file_exists($user_file))
            $fh = fopen($user_file, 'w') or die("Can't create users file");


        return file_put_contents($user_file, $this->slack_users());
    }

    /**
     * @param $user_file
     * @return mixed
     * create user file if not exist
     */
    public function create_user_file($user_file)
    {
        if(!file_exists($user_file))
            $fh = fopen($user_file, 'w') or die("Can't create file");

        return $user_file;
    }

    /**
     * add a new question to question's list
     * @param $question_text
     * @return false|int
     */
    public function add_question($question_text)
    {
        $questions_file = __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'questions.json';

        if(!file_exists($questions_file))
            $fh = fopen($questions_file, 'w') or die("Can't create file");

        $questions = json_decode($this->get_questions());

        $id = $questions[count($questions)-1]->question_id + 1 ;
        $questions[] = (object) array("question_id"=>$id,"question_text"=>$question_text);


        return file_put_contents($questions_file,json_encode($questions));
    }

    /**
     * delete a question from question's list
     * @param $question_id
     * @return false|int
     */
    public function delete_question($question_id)
    {
        $questions_file = __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'questions.json';

        if(!file_exists($questions_file))
            $fh = fopen($questions_file, 'w') or die("Can't create file");

        $questions = json_decode($this->get_questions());

        $new_questions = array();
        foreach($questions as $question){
            if($question->question_id == $question_id)
                continue;

            $new_questions[] = $question;
        }

        return file_put_contents($questions_file,json_encode($new_questions));
    }

    /**
     * get all current conversations
     * @return array
     */
    public function conversations()
    {
        $content = array();
        $files =  scandir( __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR);
        foreach($files as $file){
            if($file=="." || $file==".." || $file=="questions.json" || $file=="users.json")
                continue;

            $user_file = __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.$file;
            $data = json_decode(file_get_contents($user_file));
            if(isset($data->profile)) {
                $profile = $data->profile;
                $name = $profile->name;
            }
            else $name = "undefined";

            $content[] = ['name'=>$name,'channel'=>$file];
        }

        return $content;
    }

    /**
     * get users' list from data source
     * @return false|string
     */
    public function users()
    {
        $users_file = __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'users.json';

        if(!file_exists($users_file)) {
            $fh = fopen($users_file, 'w') or die("Can't create file");
            $this->refresh_users_list();
        }

        return file_get_contents($users_file);
    }

    /**
     * get user's profile data from users' list
     * @param $search_data
     * @param $user_id
     * @return string
     */
    public function get_user_data($search_data , $user_id)
    {
        $users = json_decode($this->users());

        foreach($users as $user){
            if($user->id == $user_id)
              return $user->$search_data;
        }
        return "Undefined";
    }

    /**
     * get conversation from user's file
     * @param $file
     * @return bool|false|string
     */
    public function conversation($file)
    {
        $users_file = __DIR__ . DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.$file;
        $users_file  = realpath($users_file);

        if(!file_exists($users_file))
           return false;

        return file_get_contents($users_file);
    }
}