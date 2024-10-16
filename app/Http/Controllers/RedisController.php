<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use App\Models\User;
use App\Models\Babble;
use App\Models\Comment;

class RedisController extends Controller
{
    public $items_per_page = 10;
    public $user_props = ['id', 'name', 'avatar', 'cover', 'following', 'followers', 'language', 'theme', 'sorting', 'created_at', 'last_action'];
    public $post_props = ['id', 'user_id', 'message', 'created_at', 'updated_at', 'babble_id', 'babble_user_id'];
    public $comment_props = ['id', 'user_id', 'commentable_id', 'commentable_type', 'message', 'created_at', 'updated_at'];
    private $user_cache_time = 20;
    private $post_cache_time = 31536000;
    
    public function get_details($messages, $type = 'babble') {
        foreach($messages as $message) {
            $message->user = $this->get_user($message->user_id);

            if($type == 'babble') {
                $this->get_reply_message($message);
                $this->get_comments($message);
                $this->get_all_replies($message);
            }

            $this->get_likes($message);
            $this->get_links($message);
            $this->get_images($message);
            $this->get_videos($message);
            $this->get_files($message);
        }

        return $messages;
    }


    public function get_user($id) {
        $user = Redis::get("user:$id");
        
        if(empty($user)) {
            $user = User::select($this->user_props)->find($id);
            if(!empty($user)) {
                $this->set_user($user);
            }
        } else {
            $user = json_decode($user);
        }
        
        return $user;
    }
    public function set_user($value) {
        $list = [];

        if(!is_array($value)) {
            $list[] = $value;
        } else {
            $list = $value;
        }

        foreach($list as $user) {
            $user = (object)$user;
            Redis::setex("user:$user->id", $this->user_cache_time, json_encode($user));
        }
    }


    private function get_all_replies($message) {
        $keys = Redis::keys("babble:*");
        rsort($keys, SORT_NATURAL);
        $array = $message->replies;
        
        foreach($keys as $key) {
            $value = json_decode(Redis::get($key));
            
            if($value->babble_id == $message->id) {
                $value->user = $this->get_user($value->user_id);
                $array[] = $value;
            }
        }

        $message->replies = $array;
    }
    private function get_reply_message($message) {
        if(!empty($message->babble_id)) {
            $reply = $this->get_post_message($message->babble_id);

            if(!empty($reply->id)) {
                $this->get_links($reply);
                $this->get_images($reply);
                $this->get_videos($reply);
            }
            
            $user = $this->get_user($message->babble_user_id);
            $reply->user = $user;
            $message->reply = $reply;
        }
    }
    public function get_post_message($id) {
        $message = Redis::get("babble:$id");

        if(empty($message)) {
            $message = Babble::select($this->post_props)->find($id);

            if(!empty($message)) {
                $this->set_post_message($message);
            } else {
                $message = (object)[];
            }
        } else {
            $message = json_decode($message);
        }

        return $message;
    }
    public function set_post_message($message) {
        if(empty($message->babble_id)) {
            $message->babble_id = null;
            $message->babble_user_id = null;
        }

        $message->type = 'babble';
        $message->replies = [];
        $message->comments = [];
        $message->likes = [];
        $message->links = [];
        $message->images = [];
        $message->videos = [];
        $message->files = [];

        Redis::setex("$message->type:$message->id", $this->post_cache_time, json_encode($message));
        return $message;
    }
    public function update_post_message($post, $post_message) {
        $post->message = $post_message;
        $post->updated_at = now();
        return $this->set_post_message($post);
    }
    public function delete_post_message($id) {
        Redis::del("babble:$id");
    }


    private function get_comments($message) {
        $keys = Redis::keys("comment:*");
        sort($keys, SORT_NATURAL);
        $array = $message->comments;
        
        foreach($keys as $key) {
            $id = substr($key, 8);
            $value = $this->get_comment($id);

            if(!empty($value)) {
                if($value->commentable_id == $message->id && $value->commentable_type == $message->type) {
                    $value = $this->get_details([$value], 'comment');
                    $array[] = $value[0];
                }
            }
        }

        $message->comments = $array;
    }
    public function get_comment($id) {
        $comment = Redis::get("comment:$id");

        if(empty($comment)) {
            $comment = Comment::select($this->comment_props)->find($id);

            if(!empty($comment)) {
                $this->set_comment($comment);
            } else {
                $comment = (object)[];
            }
        } else {
            $comment = json_decode($comment);
        }

        return $comment;
    }
    public function set_comment($comment) {
        $comment->type = 'comment';
        $comment->replies = [];
        $comment->likes = [];
        $comment->links = [];
        $comment->images = [];
        $comment->videos = [];
        $comment->files = [];

        Redis::setex("$comment->type:$comment->id", $this->post_cache_time, json_encode($comment));
        return $comment;
    }
    public function update_comment($post, $post_message) {
        $post->message = $post_message;
        $post->updated_at = now();
        return $this->set_comment($post);
    }
    public function delete_comment($id) {
        Redis::del("comment:$id");
    }
    

    public function get_likes($message) {
        $keys = Redis::keys("like:*");
        $array = $message->likes;
        
        foreach($keys as $key) {
            $value = json_decode(Redis::get($key));
            
            if($value->likeable_id == $message->id && $value->likeable_type == $message->type) {
                $array[] = $this->get_user($value->user_id);
            }
        }

        $message->likes = $array;
    }
    public function set_like($like) {
        Redis::setex("like:$like->id", $this->post_cache_time, json_encode($like));
    }
    public function delete_like($id) {
        Redis::del("like:$id");
    }


    public function get_links($message) {
        $keys = Redis::keys("link:*");
        sort($keys, SORT_NATURAL);
        $array = $message->links ?? [];
        
        foreach($keys as $key) {
            $value = json_decode(Redis::get($key));
            
            if($value->linkable_id == $message->id && $value->linkable_type == $message->type) {
                $array[] = $value;
            }
        }

        $message->links = $array;
    }
    public function set_link($link) {
        Redis::setex("link:$link->id", $this->post_cache_time, json_encode($link));
    }
    public function delete_link($id) {
        Redis::del("link:$id");
    }


    public function get_photos($user_id, $quantity = null) {
        $keys = Redis::keys("image:*");
        rsort($keys, SORT_NATURAL);
        $array = [];
        $length = count($keys);

        for($i = 0; $i < $length; $i++) {
            if(isset($keys[$i])) {
                $value = json_decode(Redis::get($keys[$i]));
            
                if($value->user_id == $user_id && $value->home_page == true) {
                    $this->get_comments($value);
                    $this->get_likes($value);
                    $array[] = $value;
                }
            }
        }
        
        if(count($array) > 0) {
            if(isset($quantity)) {
                return array_chunk($array, $quantity)[0];
            }
        }
        
        return $array;
    }
    public function get_images($message) {
        $keys = Redis::keys("image:*");
        sort($keys, SORT_NATURAL);
        $array = $message->images ?? [];
        
        foreach($keys as $key) {
            $value = json_decode(Redis::get($key));
            
            if($value->imageable_id == $message->id && $value->imageable_type == $message->type) {
                $this->get_comments($value);
                $this->get_likes($value);
                $array[] = $value;
            }
        }

        $message->images = $array;
    }
    public function set_image($image) {
        $image->type = 'image';
        $image->comments = [];
        $image->likes = [];
        Redis::setex("image:$image->id", $this->post_cache_time, json_encode($image));
        
    }
    public function delete_image($id) {
        Redis::del("image:$id");
    }


    public function get_videos($message) {
        $keys = Redis::keys("video:*");
        sort($keys, SORT_NATURAL);
        $array = $message->videos ?? [];
        
        foreach($keys as $key) {
            $value = json_decode(Redis::get($key));
            
            if($value->videoable_id == $message->id && $value->videoable_type == $message->type) {
                $array[] = $value;
            }
        }

        $message->videos = $array;
    }
    public function set_video($video) {
        $video->type = 'video';
        $video->comments = [];
        $video->likes = [];

        Redis::setex("video:$video->id", $this->post_cache_time, json_encode($video));
    }
    public function delete_video($id) {
        Redis::del("video:$id");
    }

    public function get_files($message) {
        $keys = Redis::keys("file:*");
        sort($keys, SORT_NATURAL);
        $array = $message->files ?? [];
        
        foreach($keys as $key) {
            $value = json_decode(Redis::get($key));
            
            if($value->fileable_id == $message->id && $value->fileable_type == $message->type) {
                $array[] = $value;
            }
        }

        $message->files = $array;
    }
    public function set_file($file) {
        Redis::setex("file:$file->id", $this->post_cache_time, json_encode($file));
    }
    public function delete_file($id) {
        Redis::del("file:$id");
    }


    public function create_export_data($request, array $posts, $with_details, $items_count = null) {
        $content = (object) [];
        $request->session()->put('i', 0);
        $count = $items_count ?? $this->items_per_page;

        if(!empty($posts)) {
            $request->session()->put('content', array_chunk($posts, $count));
            $content->data = $request->session()->get('content')[$request->session()->get('i')];
        } else {
            $request->session()->put('content', $posts);
            $content->data = $request->session()->get('content');
        }
        
        count($posts) > 10 ? $content->stop = false : $content->stop = true;

        if($with_details) {
            $content->data = $this->get_details($content->data);
        }

        return $content;
    }
    public function create_posts_list($request, $type, $variable, array $id_array, $with_details = true) {
        $content = [];
        $keys = Redis::keys("$type:*");
        rsort($keys, SORT_NATURAL);
        
        foreach($keys as $key) {
            $value = json_decode(Redis::get($key));
            
            if(in_array($value->$variable, $id_array)) {
                $content[] = $value;
            }
        }
        
        return $this->create_export_data($request, $content, $with_details);
    }
    public function create_users_list(array $id_array) {
        $content = [];

        foreach($id_array as $id) {
            $value = $this->get_user($id);

            if(empty($value)) {
                $value = (object)[];
                $value->id = null;
            }

            $content[] = $value;
        }
        
        return $content;
    }
    public function turn_the_page($request) {
        $content = (object) [];
        $content->stop = false;

        $request->session()->increment('i');
        $posts = $request->session()->get('content');
        $content->data = $posts[$request->session()->get('i')];

        if(count($posts) == $request->session()->get('i') + 1) {
            $content->stop = true;
        }

        return $content;
    }

    public function get_session($request) {
        $session = (object)[];

        if($request->user()) {
            $session->theme = $request->session()->get('theme', $request->user()->theme);
            $session->language = $request->session()->get('language', $request->user()->language);
        } else {
            $session->theme = $request->session()->get('theme', 0);
            $session->language = $request->session()->get('language', 'en');
        }

        return $session;
    }
    public function get_localization() {
        $localization = (object)[];
        $localization->ru =  __('localization', [], 'ru');
        $localization->en =  __('localization', [], 'en');

        return $localization;
    }
}