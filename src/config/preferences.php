<?php

return array(

  /*
  |--------------------------------------------------------------------------
  | Pagination settings
  |--------------------------------------------------------------------------
  */
  'threads_per_category' => 20,
  'posts_per_thread' => 15,
  'pagination_view' => '',

  /*
  |--------------------------------------------------------------------------
  | Cache settings
  |--------------------------------------------------------------------------
  |
  | Duration to cache data such as thread and post counts (in minutes).
  |
  */
  'cache_lifetime' => 5,

  /*
  |--------------------------------------------------------------------------
  | Misc settings
  |--------------------------------------------------------------------------
  */
  // Soft Delete: disable this if you want threads and posts to be permanently
  // removed from your database when they're deleted by a user.
  'soft_delete' => TRUE

);
