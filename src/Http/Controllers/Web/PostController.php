<?php

namespace TeamTeaTime\Forum\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\View\View;
use TeamTeaTime\Forum\Events\UserCreatingPost;
use TeamTeaTime\Forum\Events\UserEditingPost;
use TeamTeaTime\Forum\Events\UserViewingPost;
use TeamTeaTime\Forum\Http\Requests\CreatePost;
use TeamTeaTime\Forum\Http\Requests\DeletePost;
use TeamTeaTime\Forum\Http\Requests\RestorePost;
use TeamTeaTime\Forum\Http\Requests\UpdatePost;
use TeamTeaTime\Forum\Models\Post;
use TeamTeaTime\Forum\Models\Thread;
use TeamTeaTime\Forum\Support\Web\Forum;
use DB;
use Storage;

class PostController extends BaseController
{
    public function show(Request $request, Thread $thread, string $postSlug, Post $post): View
    {
        if (! $thread->category->isAccessibleTo($request->user())) {
            abort(404);
        }

        if ($thread->category->is_private) {
            $this->authorize('view', $thread);
        }

        if ($request->user() !== null) {
            UserViewingPost::dispatch($request->user(), $post);
        }

        return ViewFactory::make('forum::post.show', compact('thread', 'post'));
    }

    public function create(Request $request, Thread $thread): View
    {

        $this->authorize('reply', $thread);

        UserCreatingPost::dispatch($request->user(), $thread);

        $post = $request->has('post') ? $thread->posts->find($request->input('post')) : null;

        return ViewFactory::make('forum::post.create', compact('thread', 'post'));
    }

    public function store(CreatePost $request, Thread $thread): RedirectResponse
    {
//        dd($request);

        $this->authorize('reply', $thread);

        $post = $request->fulfill();

        if(!empty($request->whiteboard_id))
        {
//            DB::table('forum_posts')->where('id',$post['id'])->update

            $this->GenerateThumbnail($request->whiteboard_id,$post['id']);
        }

        Forum::alert('success', 'general.reply_added');

        return new RedirectResponse(Forum::route('thread.show', $post));

    }

    public function GenerateThumbnail($whiteboardid,$post_id)
    {


        $params = 'whiteboardid='.$whiteboardid.'&readonly=true';

//        dump(env('APP_WHITEBOARD').$params);

        $payload = array(
            'key' => 'h0hJHUKX0He2JadtzLk3mV8todaJZ8RFlxPkrwQcTk3eaoHa1d',
            'url' => env('APP_WHITEBOARD').$params,
            'height' => 1080, // Optional
            'width' => 1920, // Optional
        );

        $payload = json_encode($payload);

        $ch = curl_init('http://screeenly.com/api/v1/fullsize');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload))
        );

        $result = curl_exec($ch);

//        var_dump($result);

        $result = json_decode($result);

        if(!empty($result->path))
        {


            $url = $result->path;
            $contents = file_get_contents($url);
            $name = substr($url, strrpos($url, '/') + 1);
            Storage::put('public/whiteboard_previews/'.$name, $contents);

            $data = array(
                'wbd_name'=>$name,
                'post_id'=>$post_id,
                'created_at' =>now(),
                'updated_at' =>now(),
            );

            DB::table('whiteboard_previews')->insert($data);


        }


    }



    public function edit(Request $request, Thread $thread, $threadSlug, Post $post): View
    {
        if ($post->trashed()) {
            return abort(404);
        }

        $this->authorize('edit', $post);

        UserEditingPost::dispatch($request->user(), $post);

        $thread = $post->thread;
        $category = $post->thread->category;

        return ViewFactory::make('forum::post.edit', compact('category', 'thread', 'post'));
    }

    public function update(UpdatePost $request, Thread $thread, $threadSlug, Post $post): RedirectResponse
    {
        $this->authorize('edit', $post);

        $post = $request->fulfill();

        Forum::alert('success', 'posts.updated');

        return new RedirectResponse(Forum::route('thread.show', $post));
    }

    public function confirmDelete(Request $request, Thread $thread, $threadSlug, Post $post): View
    {
        return ViewFactory::make('forum::post.confirm-delete', ['category' => $thread->category, 'thread' => $thread, 'post' => $post]);
    }

    public function confirmRestore(Request $request, Thread $thread, $threadSlug, Post $post): View
    {
        return ViewFactory::make('forum::post.confirm-restore', ['category' => $thread->category, 'thread' => $thread, 'post' => $post]);
    }

    public function delete(DeletePost $request): RedirectResponse
    {
        $post = $request->fulfill();

        if ($post === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'posts.deleted', 1);

        return new RedirectResponse(Forum::route('thread.show', $post->thread));
    }

    public function restore(RestorePost $request): RedirectResponse
    {
        $post = $request->fulfill();

        if ($post === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'posts.updated', 1);

        return new RedirectResponse(Forum::route('thread.show', $post));
    }
}
