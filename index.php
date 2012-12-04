<?php
ini_set('display_errors', 'On');

# get the mongo db name out of the env
if(!$mongo_url = getenv("MONGOHQ_URL"))
{
    //default to a local dev database
    $mongo_url = 'mongodb://localhost/xfuse';
}
$mongo_url_parts = parse_url($mongo_url);
$dbname = str_replace("/", "", $mongo_url_parts["path"]);

# connect
$mongo   = new Mongo($mongo_url);
$db = $mongo->$dbname;

require_once __DIR__.'/vendor/autoload.php';

$app = new Silex\Application();
$app['mongo'] = $mongo;

$app->get('/api/user', function (\Symfony\Component\HttpFoundation\Request $request) use ($app, $db) {

    $user = null;


    print_r(getallheaders());die();

    //if an access token was presented, attempt to authenticate with that
    //this could have been from the SSO workflow with MemberFuse
    if($request->headers->has('authorization'))
    {
        $auth = explode(' ',$request->headers->get('authorization'));
        if($auth[0] == 'Bearer')
        {
            $token = $auth[1];
            if(!$token = $db->tokens->findOne(array('token' => $token)))
            {
                $app->abort(401, 'invalid_token');
            }

            if(!$user = $db->users->findOne(array('_id' => $token['user_id'])))
            {
                $app->abort(401, 'unauthorized_token');
            }
        }
        else
        {
            $app->abort(400, 'invalid authorization type. must be Bearer ' . $auth[0] . ' given');
        }
    }

    //if above didn't produce a user try with user credentials from the basic auth
    if(!$user)
    {
        if(!$user = $db->users->findOne(array('username' => $request->getUser(), 'password' => $request->getPassword())))
        {
            $app->abort(401, 'user credentials authentication failed');
        }
    }

    //add the id fields MemberFuse is looking for
    $user['external_id'] = (string)$user['_id'];

    return $app->json($user);
});



$app->match('/oauth/authorize', function(\Symfony\Component\HttpFoundation\Request $request) use ($app, $db){

    if($request->isMethod("POST"))
    {
        //validate the user
        if(!$user = $db->users->findOne(array('username'=>$request->request->get('username'), 'password'=>$request->request->get('password'))))
        {
            return "Invalid Login";
        }


        if($redirect_uri = $request->query->get('redirect_uri'))
        {
            $auth_code = sha1(uniqid());
            $db->auth_codes->save(array('code'=>$auth_code, 'user_id' => $user['_id']));

            if(parse_url($redirect_uri, PHP_URL_QUERY))
            {
                $redirect_uri .= '&';
            }
            else
            {
                $redirect_uri .= '?';
            }

            $redirect_uri .= 'code=' . urldecode($auth_code);
            $redirect_uri .= '&state=' . urlencode($request->query->get('state'));

            return $app->redirect($redirect_uri);
        }

        return "Welcome " . $user['firstname'];

    }

$html = <<< HTML
<html>
    <head>
        <title>Login</title>
    </head>
    <body>
        <p>Please login below</p>
        <form method="POST">
            <label>Username</label>
            <input type="text" name="username" />
            <label>Password</label>
            <input type="password" name="password" />
            <input type="submit" value="Login" />
        </form>
    </body>
</html>
HTML;

    return $html;

})
->method("GET|POST");


$app->post('/oauth/token', function(\Symfony\Component\HttpFoundation\Request $request) use ($app, $db){
    if(!$code = $request->request->get('code'))
    {
        $app->abort(400, 'invalid_request');
    }

    if(!$code = $db->auth_codes->findOne(array('code'=>$code)))
    {
        return $app->abort(400, 'invalid_grant');
    }

    $user = $db->users->findOne(array('_id' => $code['user_id']));

    $token = sha1(uniqid());

    $db->tokens->save(array('token'=>$token, 'user_id'=>$user['_id']));

    header('Content-Type: application/json');
    return $app->json(array(
        'access_token' => $token,
        'token_type' => 'example',
    ),200);

});


$app->error(function(\Exception $e, $code) use ($app) {
    return $app->json(array('error' => $e->getMessage()), $code);
});

$app->run();