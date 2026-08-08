<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use StormWatch\App;
use StormWatch\Auth;
use StormWatch\Http;

App::boot('public');
Auth::logout();
Http::redirect('login.php');
