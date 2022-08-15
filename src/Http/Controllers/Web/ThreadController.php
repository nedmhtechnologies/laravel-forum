<?php

namespace TeamTeaTime\Forum\Http\Controllers\Web;


use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\View\View;
use TeamTeaTime\Forum\Events\UserCreatingThread;
use TeamTeaTime\Forum\Events\UserViewingRecent;
use TeamTeaTime\Forum\Events\UserViewingThread;
use TeamTeaTime\Forum\Events\UserViewingUnread;
use TeamTeaTime\Forum\Http\Requests\CreateThread;
use TeamTeaTime\Forum\Http\Requests\DeleteThread;
use TeamTeaTime\Forum\Http\Requests\LockThread;
use TeamTeaTime\Forum\Http\Requests\MarkThreadsAsRead;
use TeamTeaTime\Forum\Http\Requests\MoveThread;
use TeamTeaTime\Forum\Http\Requests\PinThread;
use TeamTeaTime\Forum\Http\Requests\RenameThread;
use TeamTeaTime\Forum\Http\Requests\RestoreThread;
use TeamTeaTime\Forum\Http\Requests\UnlockThread;
use TeamTeaTime\Forum\Http\Requests\UnpinThread;
use TeamTeaTime\Forum\Models\Category;
use TeamTeaTime\Forum\Models\Thread;
use TeamTeaTime\Forum\Support\CategoryPrivacy;
use TeamTeaTime\Forum\Support\Web\Forum;
use APP\Http\Controllers\HelperFunctionController;
use DB;
use Storage;


class ThreadController extends BaseController
{

    public function recent(Request $request): View
    {
        $threads = Thread::recent()->with('category', 'author', 'lastPost', 'lastPost.author');

        if ($request->has('category_id')) {
            $threads = $threads->where('category_id', $request->input('category_id'));
        }

        $accessibleCategoryIds = CategoryPrivacy::getFilteredFor($request->user())->keys();

        $threads = $threads->get()->filter(function ($thread) use ($request, $accessibleCategoryIds) {
            return $accessibleCategoryIds->contains($thread->category_id) && (! $thread->category->is_private || $request->user() && $request->user()->can('view', $thread));
        });

        if ($request->user() !== null) {
            UserViewingRecent::dispatch($request->user(), $threads);
        }

        return ViewFactory::make('forum::thread.recent', compact('threads'));
    }

    public function unread(Request $request): View
    {
        $threads = Thread::recent();

        $accessibleCategoryIds = CategoryPrivacy::getFilteredFor($request->user())->keys();

        $threads = $threads->get()->filter(function ($thread) use ($request, $accessibleCategoryIds) {
            return $thread->userReadStatus !== null
                && (! $thread->category->is_private || $request->user() && $accessibleCategoryIds->contains($thread->category_id) && $request->user()->can('view', $thread));
        });

        if ($request->user() !== null) {
            UserViewingUnread::dispatch($request->user(), $threads);
        }

        return ViewFactory::make('forum::thread.unread', compact('threads'));
    }

    public function markAsRead(MarkThreadsAsRead $request): RedirectResponse
    {
        $category = $request->fulfill();

        if ($category !== null) {
            Forum::alert('success', 'categories.marked_read', 1, ['category' => $category->title]);

            return new RedirectResponse(Forum::route('category.show', $category));
        }

        Forum::alert('success', 'threads.marked_read');

        return new RedirectResponse(Forum::route('unread'));
    }

    public function show(Request $request, Thread $thread): View
    {
        if (! $thread->category->isAccessibleTo($request->user())) {
            abort(404);
        }

        if ($thread->category->is_private) {
            $this->authorize('view', $thread);
        }

        if ($request->user() !== null) {
            UserViewingThread::dispatch($request->user(), $thread);
            $thread->markAsRead($request->user()->getKey());
        }

        $category = $thread->category;
        $categories = $request->user() && $request->user()->can('moveThreadsFrom', $category)
                    ? Category::acceptsThreads()->get()->toTree()
                    : [];

        $posts = config('forum.general.display_trashed_posts') || $request->user() && $request->user()->can('viewTrashedPosts')
               ? $thread->posts()->withTrashed()
               : $thread->posts();

        $posts = $posts
            ->with('author', 'thread')
            ->orderBy('created_at', 'asc')
            ->paginate();

        $selectablePosts = [];

        if ($request->user()) {
            foreach ($posts as $post) {
                if ($request->user()->can('delete', $post) || $request->user()->can('restore', $post)) {
                    $selectablePosts[] = $post->id;
                }
            }
        }

        return ViewFactory::make('forum::thread.show', compact('categories', 'category', 'thread', 'posts', 'selectablePosts'));
    }

    public function create(Request $request, Category $category): View
    {

        if (! $category->accepts_threads) {
            Forum::alert('warning', 'categories.threads_disabled');

            return new RedirectResponse(Forum::route('category.show', $category));
        }

        if ($request->user() !== null) {
            UserCreatingThread::dispatch($request->user(), $category);
        }

        return ViewFactory::make('forum::thread.create', compact('category'));
    }

    public function store(CreateThread $request, Category $category): RedirectResponse
    {

        $thread = $request->fulfill();

        if(!empty($request->whiteboard_id))
        {
            DB::table('forum_threads')->where('id',$thread['id'])->update(['whiteboard_id'=>$request->whiteboard_id]);


            $this->GenerateThumbnail($request->whiteboard_id,$thread['id']);
        }

        Forum::alert('success', 'threads.created');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }



    public function GenerateThumbnail($whiteboardid,$forum_id)
    {


        $params = 'whiteboardid='.$whiteboardid.'&readonly=true';

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
                'forum_id'=>$forum_id,
                'created_at' =>now(),
                'updated_at' =>now(),
            );

            DB::table('whiteboard_previews')->insert($data);


        }


    }



    public function lock(LockThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        if ($thread === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'threads.updated');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }

    public function unlock(UnlockThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        if ($thread === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'threads.updated');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }

    public function pin(PinThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        if ($thread === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'threads.updated');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }

    public function unpin(UnpinThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        if ($thread === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'threads.updated');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }

    public function rename(RenameThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        Forum::alert('success', 'threads.updated');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }

    public function move(MoveThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        if ($thread === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'threads.updated');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }

    public function delete(DeleteThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        if ($thread === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'threads.deleted');

        return new RedirectResponse(Forum::route('category.show', $thread->category));
    }

    public function restore(RestoreThread $request): RedirectResponse
    {
        $thread = $request->fulfill();

        if ($thread === null) {
            return $this->invalidSelectionResponse();
        }

        Forum::alert('success', 'threads.updated');

        return new RedirectResponse(Forum::route('thread.show', $thread));
    }
}
