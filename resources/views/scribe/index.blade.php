<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>API Documentation</title>

    <link href="https://fonts.googleapis.com/css?family=PT+Sans&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset("vendor/scribe/css/theme-default.style.css") }}" media="screen">
    <link rel="stylesheet" href="{{ asset("vendor/scribe/css/theme-default.print.css") }}" media="print">

    <script src="https://cdn.jsdelivr.net/npm/lodash@4.17.10/lodash.min.js"></script>

    <link rel="stylesheet"
          href="https://unpkg.com/@highlightjs/cdn-assets@10.7.2/styles/obsidian.min.css">
    <script src="https://unpkg.com/@highlightjs/cdn-assets@10.7.2/highlight.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jets/0.14.1/jets.min.js"></script>

    <style id="language-style">
        /* starts out as display none and is replaced with js later  */
                    body .content .javascript-example code { display: none; }
                    body .content .bash-example code { display: none; }
                    body .content .php-example code { display: none; }
                    body .content .python-example code { display: none; }
            </style>


    <script src="{{ asset("vendor/scribe/js/theme-default-3.20.0.js") }}"></script>

</head>

<body data-languages="[&quot;javascript&quot;,&quot;bash&quot;,&quot;php&quot;,&quot;python&quot;]">

<a href="#" id="nav-button">
    <span>
        MENU
        <img src="{{ asset("vendor/scribe/images/navbar.png") }}" alt="navbar-image" />
    </span>
</a>
<div class="tocify-wrapper">
    
            <div class="lang-selector">
                                            <button type="button" class="lang-button" data-language-name="javascript">javascript</button>
                                            <button type="button" class="lang-button" data-language-name="bash">bash</button>
                                            <button type="button" class="lang-button" data-language-name="php">php</button>
                                            <button type="button" class="lang-button" data-language-name="python">python</button>
                    </div>
    
    <div class="search">
        <input type="text" class="search" id="input-search" placeholder="Search">
    </div>

    <div id="toc">
                                                                            <ul id="tocify-header-0" class="tocify-header">
                    <li class="tocify-item level-1" data-unique="introduction">
                        <a href="#introduction">Introduction</a>
                    </li>
                                            
                                                                    </ul>
                                                <ul id="tocify-header-1" class="tocify-header">
                    <li class="tocify-item level-1" data-unique="authenticating-requests">
                        <a href="#authenticating-requests">Authenticating requests</a>
                    </li>
                                            
                                                </ul>
                    
                    <ul id="tocify-header-2" class="tocify-header">
                <li class="tocify-item level-1" data-unique="endpoints">
                    <a href="#endpoints">Endpoints</a>
                </li>
                                    <ul id="tocify-subheader-endpoints" class="tocify-subheader">
                                                    <li class="tocify-item level-2" data-unique="endpoints-POSTapi-auth-login">
                        <a href="#endpoints-POSTapi-auth-login">Login</a>
                    </li>
                                    <li class="tocify-item level-2" data-unique="endpoints-GETapi-user">
                        <a href="#endpoints-GETapi-user">User Profile</a>
                    </li>
                                    <li class="tocify-item level-2" data-unique="endpoints-POSTapi-auth-register">
                        <a href="#endpoints-POSTapi-auth-register">Register</a>
                    </li>
                                    <li class="tocify-item level-2" data-unique="endpoints-POSTapi-auth-forgot-password">
                        <a href="#endpoints-POSTapi-auth-forgot-password">Forgot Password</a>
                    </li>
                                    <li class="tocify-item level-2" data-unique="endpoints-POSTapi-user-avatar">
                        <a href="#endpoints-POSTapi-user-avatar">Update User Avatar</a>
                    </li>
                                    <li class="tocify-item level-2" data-unique="endpoints-POSTapi-user-password">
                        <a href="#endpoints-POSTapi-user-password">Change User Password</a>
                    </li>
                                    <li class="tocify-item level-2" data-unique="endpoints-POSTapi-user">
                        <a href="#endpoints-POSTapi-user">Update User Profile</a>
                    </li>
                                    <li class="tocify-item level-2" data-unique="endpoints-POSTapi-auth-logout">
                        <a href="#endpoints-POSTapi-auth-logout">POST api/auth/logout</a>
                    </li>
                                                    </ul>
                            </ul>
        
                        
            </div>

            <ul class="toc-footer" id="toc-footer">
                            <li><a href="{{ route("scribe.postman") }}">View Postman collection</a></li>
                            <li><a href="{{ route("scribe.openapi") }}">View OpenAPI spec</a></li>
                            <li><a href="http://github.com/knuckleswtf/scribe">Documentation powered by Scribe ✍</a></li>
                    </ul>
        <ul class="toc-footer" id="last-updated">
        <li>Last updated: January 1 2022</li>
    </ul>
</div>

<div class="page-wrapper">
    <div class="dark-box"></div>
    <div class="content">
        <h1 id="introduction">Introduction</h1>
<p>Documentation for the API of this backend.</p>
<p>This documentation aims to provide all the information you need to work with our API.</p>
<aside>As you scroll, you'll see code examples for working with the API in different programming languages in the dark area to the right (or as part of the content on mobile).
You can switch the language used with the tabs at the top right (or from the nav menu at the top left on mobile).</aside>
<blockquote>
<p>Base URL</p>
</blockquote>
<pre><code class="language-yaml">http://localhost:8000</code></pre>

        <h1 id="authenticating-requests">Authenticating requests</h1>
<p>To authenticate requests, include an <strong><code>Authorization</code></strong> header with the value <strong><code>"Bearer {YOUR_AUTH_KEY}"</code></strong>.</p>
<p>All authenticated endpoints are marked with a <code>requires authentication</code> badge in the documentation below.</p>
<p>You can retrieve your token by logging in via the <code>/api/auth/login</code> endpoint.</p>

        <h1 id="endpoints">Endpoints</h1>

    

            <h2 id="endpoints-POSTapi-auth-login">Login</h2>

<p>
</p>

<p>Checks the supplied username and password and returns a token if the details are valid</p>

<span id="example-requests-POSTapi-auth-login">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/auth/login"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "username": "eius",
    "password": "est"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/auth/login" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"username\": \"eius\",
    \"password\": \"est\"
}"
</code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;post(
    'http://localhost:8000/api/auth/login',
    [
        'headers' =&gt; [
            'Content-Type' =&gt; 'application/json',
            'Accept' =&gt; 'application/json',
        ],
        'json' =&gt; [
            'username' =&gt; 'eius',
            'password' =&gt; 'est',
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/auth/login'
payload = {
    "username": "eius",
    "password": "est"
}
headers = {
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}

response = requests.request('POST', url, headers=headers, json=payload)
response.json()</code></pre></div>

</span>

<span id="example-responses-POSTapi-auth-login">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Successful&quot;,
    &quot;data&quot;: {
        &quot;user&quot;: {
            &quot;id&quot;: 23,
            &quot;name&quot;: &quot;User&quot;,
            &quot;username&quot;: &quot;User&quot;,
            &quot;email&quot;: &quot;user@example.com&quot;,
            &quot;avatar_url&quot;: &quot;https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&amp;d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&amp;r=g&quot;,
            &quot;roles&quot;: [
                &quot;User&quot;
            ]
        },
        &quot;access_token&quot;: &quot;4|XP9j2cxsNudDXf2JxPIMhe0XCNHIPzEPIMYC7RuL&quot;
    }
}</code>
 </pre>
            <blockquote>
            <p>Example response (400, Invalid username or password):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Invalid Username or Password&quot;
}</code>
 </pre>
    </span>
<span id="execution-results-POSTapi-auth-login" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-auth-login"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-auth-login"></code></pre>
</span>
<span id="execution-error-POSTapi-auth-login" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-auth-login"></code></pre>
</span>
<form id="form-POSTapi-auth-login" data-method="POST"
      data-path="api/auth/login"
      data-authed="0"
      data-hasfiles="0"
      data-isarraybody="0"
      data-headers='{"Content-Type":"application\/json","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-auth-login', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/auth/login</code></b>
        </p>
                            <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
        <p>
            <b><code>username</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="username"
               data-endpoint="POSTapi-auth-login"
               value="eius"
               data-component="body" hidden>
    <br>
<p>The user's name</p>
        </p>
                <p>
            <b><code>password</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="password"
               data-endpoint="POSTapi-auth-login"
               value="est"
               data-component="body" hidden>
    <br>
<p>The user's password</p>
        </p>
        </form>

            <h2 id="endpoints-GETapi-user">User Profile</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Gets the current authenticated user profile including name, username, email and avatar url.</p>

<span id="example-requests-GETapi-user">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/user"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/user" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;get(
    'http://localhost:8000/api/user',
    [
        'headers' =&gt; [
            'Authorization' =&gt; 'Bearer {YOUR_AUTH_KEY}',
            'Content-Type' =&gt; 'application/json',
            'Accept' =&gt; 'application/json',
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/user'
headers = {
  'Authorization': 'Bearer {YOUR_AUTH_KEY}',
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}

response = requests.request('GET', url, headers=headers)
response.json()</code></pre></div>

</span>

<span id="example-responses-GETapi-user">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Successful&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 23,
        &quot;name&quot;: &quot;User&quot;,
        &quot;username&quot;: &quot;User&quot;,
        &quot;email&quot;: &quot;user@example.com&quot;,
        &quot;avatar_url&quot;: &quot;https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&amp;d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&amp;r=g&quot;,
        &quot;roles&quot;: [
            &quot;User&quot;
        ]
    }
}</code>
 </pre>
    </span>
<span id="execution-results-GETapi-user" hidden>
    <blockquote>Received response<span
                id="execution-response-status-GETapi-user"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-GETapi-user"></code></pre>
</span>
<span id="execution-error-GETapi-user" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-GETapi-user"></code></pre>
</span>
<form id="form-GETapi-user" data-method="GET"
      data-path="api/user"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      data-headers='{"Authorization":"Bearer {YOUR_AUTH_KEY}","Content-Type":"application\/json","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('GETapi-user', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-green">GET</small>
            <b><code>api/user</code></b>
        </p>
                <p>
            <label id="auth-GETapi-user" hidden>Authorization header:
                <b><code>Bearer </code></b><input type="text"
                                                                name="Authorization"
                                                                data-prefix="Bearer "
                                                                data-endpoint="GETapi-user"
                                                                data-component="header"></label>
        </p>
                </form>

            <h2 id="endpoints-POSTapi-auth-register">Register</h2>

<p>
</p>

<p>Registeres a new user.</p>

<span id="example-requests-POSTapi-auth-register">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/auth/register"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "name": "rerum",
    "username": "consequatur",
    "email": "officiis",
    "password": "aut",
    "password_confirmation": "ipsa"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/auth/register" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"name\": \"rerum\",
    \"username\": \"consequatur\",
    \"email\": \"officiis\",
    \"password\": \"aut\",
    \"password_confirmation\": \"ipsa\"
}"
</code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;post(
    'http://localhost:8000/api/auth/register',
    [
        'headers' =&gt; [
            'Content-Type' =&gt; 'application/json',
            'Accept' =&gt; 'application/json',
        ],
        'json' =&gt; [
            'name' =&gt; 'rerum',
            'username' =&gt; 'consequatur',
            'email' =&gt; 'officiis',
            'password' =&gt; 'aut',
            'password_confirmation' =&gt; 'ipsa',
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/auth/register'
payload = {
    "name": "rerum",
    "username": "consequatur",
    "email": "officiis",
    "password": "aut",
    "password_confirmation": "ipsa"
}
headers = {
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}

response = requests.request('POST', url, headers=headers, json=payload)
response.json()</code></pre></div>

</span>

<span id="example-responses-POSTapi-auth-register">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Successful&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 23,
        &quot;name&quot;: &quot;User&quot;,
        &quot;username&quot;: &quot;User&quot;,
        &quot;email&quot;: &quot;user@example.com&quot;,
        &quot;avatar_url&quot;: &quot;https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&amp;d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&amp;r=g&quot;,
        &quot;roles&quot;: [
            &quot;User&quot;
        ]
    }
}</code>
 </pre>
    </span>
<span id="execution-results-POSTapi-auth-register" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-auth-register"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-auth-register"></code></pre>
</span>
<span id="execution-error-POSTapi-auth-register" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-auth-register"></code></pre>
</span>
<form id="form-POSTapi-auth-register" data-method="POST"
      data-path="api/auth/register"
      data-authed="0"
      data-hasfiles="0"
      data-isarraybody="0"
      data-headers='{"Content-Type":"application\/json","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-auth-register', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/auth/register</code></b>
        </p>
                            <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
        <p>
            <b><code>name</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="name"
               data-endpoint="POSTapi-auth-register"
               value="rerum"
               data-component="body" hidden>
    <br>
<p>The new user's name</p>
        </p>
                <p>
            <b><code>username</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="username"
               data-endpoint="POSTapi-auth-register"
               value="consequatur"
               data-component="body" hidden>
    <br>
<p>The new user's username</p>
        </p>
                <p>
            <b><code>email</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="email"
               data-endpoint="POSTapi-auth-register"
               value="officiis"
               data-component="body" hidden>
    <br>
<p>The new user's email</p>
        </p>
                <p>
            <b><code>password</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="password"
               data-endpoint="POSTapi-auth-register"
               value="aut"
               data-component="body" hidden>
    <br>
<p>The password</p>
        </p>
                <p>
            <b><code>password_confirmation</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="password_confirmation"
               data-endpoint="POSTapi-auth-register"
               value="ipsa"
               data-component="body" hidden>
    <br>
<p>The password again</p>
        </p>
        </form>

            <h2 id="endpoints-POSTapi-auth-forgot-password">Forgot Password</h2>

<p>
</p>

<p>Requests a Reset Password Email.</p>

<span id="example-requests-POSTapi-auth-forgot-password">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/auth/forgot-password"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "username": "rerum"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/auth/forgot-password" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"username\": \"rerum\"
}"
</code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;post(
    'http://localhost:8000/api/auth/forgot-password',
    [
        'headers' =&gt; [
            'Content-Type' =&gt; 'application/json',
            'Accept' =&gt; 'application/json',
        ],
        'json' =&gt; [
            'username' =&gt; 'rerum',
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/auth/forgot-password'
payload = {
    "username": "rerum"
}
headers = {
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}

response = requests.request('POST', url, headers=headers, json=payload)
response.json()</code></pre></div>

</span>

<span id="example-responses-POSTapi-auth-forgot-password">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Successful&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 23,
        &quot;name&quot;: &quot;User&quot;,
        &quot;username&quot;: &quot;User&quot;,
        &quot;email&quot;: &quot;user@example.com&quot;,
        &quot;avatar_url&quot;: &quot;https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&amp;d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&amp;r=g&quot;,
        &quot;roles&quot;: [
            &quot;User&quot;
        ]
    }
}</code>
 </pre>
    </span>
<span id="execution-results-POSTapi-auth-forgot-password" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-auth-forgot-password"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-auth-forgot-password"></code></pre>
</span>
<span id="execution-error-POSTapi-auth-forgot-password" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-auth-forgot-password"></code></pre>
</span>
<form id="form-POSTapi-auth-forgot-password" data-method="POST"
      data-path="api/auth/forgot-password"
      data-authed="0"
      data-hasfiles="0"
      data-isarraybody="0"
      data-headers='{"Content-Type":"application\/json","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-auth-forgot-password', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/auth/forgot-password</code></b>
        </p>
                            <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
        <p>
            <b><code>username</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="username"
               data-endpoint="POSTapi-auth-forgot-password"
               value="rerum"
               data-component="body" hidden>
    <br>
<p>The user's username or email</p>
        </p>
        </form>

            <h2 id="endpoints-POSTapi-user-avatar">Update User Avatar</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Updates the current authenticated user's avatar.</p>

<span id="example-requests-POSTapi-user-avatar">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/user/avatar"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "multipart/form-data",
    "Accept": "application/json",
};

const body = new FormData();
body.append('photo', document.querySelector('input[name="photo"]').files[0]);

fetch(url, {
    method: "POST",
    headers,
    body,
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/user/avatar" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: multipart/form-data" \
    --header "Accept: application/json" \
    --form "photo=@/private/var/folders/xz/sb1jl4ls79n2j30yh2ysvwfh0000gn/T/phpjbUKfQ" </code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;post(
    'http://localhost:8000/api/user/avatar',
    [
        'headers' =&gt; [
            'Authorization' =&gt; 'Bearer {YOUR_AUTH_KEY}',
            'Content-Type' =&gt; 'multipart/form-data',
            'Accept' =&gt; 'application/json',
        ],
        'multipart' =&gt; [
            [
                'name' =&gt; 'photo',
                'contents' =&gt; fopen('/private/var/folders/xz/sb1jl4ls79n2j30yh2ysvwfh0000gn/T/phpjbUKfQ', 'r')
            ],
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/user/avatar'
files = {
  'photo': open('/private/var/folders/xz/sb1jl4ls79n2j30yh2ysvwfh0000gn/T/phpjbUKfQ', 'rb')
}
headers = {
  'Authorization': 'Bearer {YOUR_AUTH_KEY}',
  'Content-Type': 'multipart/form-data',
  'Accept': 'application/json'
}

response = requests.request('POST', url, headers=headers, files=files)
response.json()</code></pre></div>

</span>

<span id="example-responses-POSTapi-user-avatar">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Successful&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 23,
        &quot;name&quot;: &quot;User&quot;,
        &quot;username&quot;: &quot;User&quot;,
        &quot;email&quot;: &quot;user@example.com&quot;,
        &quot;avatar_url&quot;: &quot;https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&amp;d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&amp;r=g&quot;,
        &quot;roles&quot;: [
            &quot;User&quot;
        ]
    }
}</code>
 </pre>
    </span>
<span id="execution-results-POSTapi-user-avatar" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-user-avatar"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-user-avatar"></code></pre>
</span>
<span id="execution-error-POSTapi-user-avatar" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-user-avatar"></code></pre>
</span>
<form id="form-POSTapi-user-avatar" data-method="POST"
      data-path="api/user/avatar"
      data-authed="1"
      data-hasfiles="1"
      data-isarraybody="0"
      data-headers='{"Authorization":"Bearer {YOUR_AUTH_KEY}","Content-Type":"multipart\/form-data","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-user-avatar', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/user/avatar</code></b>
        </p>
                <p>
            <label id="auth-POSTapi-user-avatar" hidden>Authorization header:
                <b><code>Bearer </code></b><input type="text"
                                                                name="Authorization"
                                                                data-prefix="Bearer "
                                                                data-endpoint="POSTapi-user-avatar"
                                                                data-component="header"></label>
        </p>
                        <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
        <p>
            <b><code>photo</code></b>&nbsp;&nbsp;<small>file</small>     <i>optional</i> &nbsp;
                <input type="file"
               name="photo"
               data-endpoint="POSTapi-user-avatar"
               value=""
               data-component="body" hidden>
    <br>
<p>The new avatar image</p>
        </p>
        </form>

            <h2 id="endpoints-POSTapi-user-password">Change User Password</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Changes the current authenticated user's password.</p>

<span id="example-requests-POSTapi-user-password">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/user/password"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "existing_password": "consectetur",
    "password": "eum",
    "password_confirmation": "sequi"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/user/password" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"existing_password\": \"consectetur\",
    \"password\": \"eum\",
    \"password_confirmation\": \"sequi\"
}"
</code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;post(
    'http://localhost:8000/api/user/password',
    [
        'headers' =&gt; [
            'Authorization' =&gt; 'Bearer {YOUR_AUTH_KEY}',
            'Content-Type' =&gt; 'application/json',
            'Accept' =&gt; 'application/json',
        ],
        'json' =&gt; [
            'existing_password' =&gt; 'consectetur',
            'password' =&gt; 'eum',
            'password_confirmation' =&gt; 'sequi',
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/user/password'
payload = {
    "existing_password": "consectetur",
    "password": "eum",
    "password_confirmation": "sequi"
}
headers = {
  'Authorization': 'Bearer {YOUR_AUTH_KEY}',
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}

response = requests.request('POST', url, headers=headers, json=payload)
response.json()</code></pre></div>

</span>

<span id="example-responses-POSTapi-user-password">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Successful&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 23,
        &quot;name&quot;: &quot;User&quot;,
        &quot;username&quot;: &quot;User&quot;,
        &quot;email&quot;: &quot;user@example.com&quot;,
        &quot;avatar_url&quot;: &quot;https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&amp;d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&amp;r=g&quot;,
        &quot;roles&quot;: [
            &quot;User&quot;
        ]
    }
}</code>
 </pre>
    </span>
<span id="execution-results-POSTapi-user-password" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-user-password"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-user-password"></code></pre>
</span>
<span id="execution-error-POSTapi-user-password" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-user-password"></code></pre>
</span>
<form id="form-POSTapi-user-password" data-method="POST"
      data-path="api/user/password"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      data-headers='{"Authorization":"Bearer {YOUR_AUTH_KEY}","Content-Type":"application\/json","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-user-password', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/user/password</code></b>
        </p>
                <p>
            <label id="auth-POSTapi-user-password" hidden>Authorization header:
                <b><code>Bearer </code></b><input type="text"
                                                                name="Authorization"
                                                                data-prefix="Bearer "
                                                                data-endpoint="POSTapi-user-password"
                                                                data-component="header"></label>
        </p>
                        <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
        <p>
            <b><code>existing_password</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="existing_password"
               data-endpoint="POSTapi-user-password"
               value="consectetur"
               data-component="body" hidden>
    <br>
<p>The existing password</p>
        </p>
                <p>
            <b><code>password</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="password"
               data-endpoint="POSTapi-user-password"
               value="eum"
               data-component="body" hidden>
    <br>
<p>The new password</p>
        </p>
                <p>
            <b><code>password_confirmation</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="password_confirmation"
               data-endpoint="POSTapi-user-password"
               value="sequi"
               data-component="body" hidden>
    <br>
<p>The new password again</p>
        </p>
        </form>

            <h2 id="endpoints-POSTapi-user">Update User Profile</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>

<p>Updates the current authenticated user profile including name, username, and email.</p>

<span id="example-requests-POSTapi-user">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/user"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "name": "perferendis",
    "username": "recusandae",
    "email": "nostrum"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/user" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"name\": \"perferendis\",
    \"username\": \"recusandae\",
    \"email\": \"nostrum\"
}"
</code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;post(
    'http://localhost:8000/api/user',
    [
        'headers' =&gt; [
            'Authorization' =&gt; 'Bearer {YOUR_AUTH_KEY}',
            'Content-Type' =&gt; 'application/json',
            'Accept' =&gt; 'application/json',
        ],
        'json' =&gt; [
            'name' =&gt; 'perferendis',
            'username' =&gt; 'recusandae',
            'email' =&gt; 'nostrum',
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/user'
payload = {
    "name": "perferendis",
    "username": "recusandae",
    "email": "nostrum"
}
headers = {
  'Authorization': 'Bearer {YOUR_AUTH_KEY}',
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}

response = requests.request('POST', url, headers=headers, json=payload)
response.json()</code></pre></div>

</span>

<span id="example-responses-POSTapi-user">
            <blockquote>
            <p>Example response (200):</p>
        </blockquote>
                <pre>

<code class="language-json">{
    &quot;message&quot;: &quot;Successful&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 23,
        &quot;name&quot;: &quot;User&quot;,
        &quot;username&quot;: &quot;User&quot;,
        &quot;email&quot;: &quot;user@example.com&quot;,
        &quot;avatar_url&quot;: &quot;https://www.gravatar.com/avatar/b58996c504c5638798eb6b511e6f49af.jpg?s=80&amp;d=https%3A%2F%2Fplacehold.it%2F160x160%2F00a65a%2Fffffff%2F%26text%3DU&amp;r=g&quot;,
        &quot;roles&quot;: [
            &quot;User&quot;
        ]
    }
}</code>
 </pre>
    </span>
<span id="execution-results-POSTapi-user" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-user"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-user"></code></pre>
</span>
<span id="execution-error-POSTapi-user" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-user"></code></pre>
</span>
<form id="form-POSTapi-user" data-method="POST"
      data-path="api/user"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      data-headers='{"Authorization":"Bearer {YOUR_AUTH_KEY}","Content-Type":"application\/json","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-user', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/user</code></b>
        </p>
                <p>
            <label id="auth-POSTapi-user" hidden>Authorization header:
                <b><code>Bearer </code></b><input type="text"
                                                                name="Authorization"
                                                                data-prefix="Bearer "
                                                                data-endpoint="POSTapi-user"
                                                                data-component="header"></label>
        </p>
                        <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
        <p>
            <b><code>name</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="name"
               data-endpoint="POSTapi-user"
               value="perferendis"
               data-component="body" hidden>
    <br>
<p>The user's name</p>
        </p>
                <p>
            <b><code>username</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="username"
               data-endpoint="POSTapi-user"
               value="recusandae"
               data-component="body" hidden>
    <br>
<p>The user's username</p>
        </p>
                <p>
            <b><code>email</code></b>&nbsp;&nbsp;<small>string</small>     <i>optional</i> &nbsp;
                <input type="text"
               name="email"
               data-endpoint="POSTapi-user"
               value="nostrum"
               data-component="body" hidden>
    <br>
<p>The user's email</p>
        </p>
        </form>

            <h2 id="endpoints-POSTapi-auth-logout">POST api/auth/logout</h2>

<p>
<small class="badge badge-darkred">requires authentication</small>
</p>



<span id="example-requests-POSTapi-auth-logout">
<blockquote>Example request:</blockquote>


<div class="javascript-example">
    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/auth/logout"
);

const headers = {
    "Authorization": "Bearer {YOUR_AUTH_KEY}",
    "Content-Type": "application/json",
    "Accept": "application/json",
};

fetch(url, {
    method: "POST",
    headers,
}).then(response =&gt; response.json());</code></pre></div>


<div class="bash-example">
    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/auth/logout" \
    --header "Authorization: Bearer {YOUR_AUTH_KEY}" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre></div>


<div class="php-example">
    <pre><code class="language-php">$client = new \GuzzleHttp\Client();
$response = $client-&gt;post(
    'http://localhost:8000/api/auth/logout',
    [
        'headers' =&gt; [
            'Authorization' =&gt; 'Bearer {YOUR_AUTH_KEY}',
            'Content-Type' =&gt; 'application/json',
            'Accept' =&gt; 'application/json',
        ],
    ]
);
$body = $response-&gt;getBody();
print_r(json_decode((string) $body));</code></pre></div>


<div class="python-example">
    <pre><code class="language-python">import requests
import json

url = 'http://localhost:8000/api/auth/logout'
headers = {
  'Authorization': 'Bearer {YOUR_AUTH_KEY}',
  'Content-Type': 'application/json',
  'Accept': 'application/json'
}

response = requests.request('POST', url, headers=headers)
response.json()</code></pre></div>

</span>

<span id="example-responses-POSTapi-auth-logout">
</span>
<span id="execution-results-POSTapi-auth-logout" hidden>
    <blockquote>Received response<span
                id="execution-response-status-POSTapi-auth-logout"></span>:
    </blockquote>
    <pre class="json"><code id="execution-response-content-POSTapi-auth-logout"></code></pre>
</span>
<span id="execution-error-POSTapi-auth-logout" hidden>
    <blockquote>Request failed with error:</blockquote>
    <pre><code id="execution-error-message-POSTapi-auth-logout"></code></pre>
</span>
<form id="form-POSTapi-auth-logout" data-method="POST"
      data-path="api/auth/logout"
      data-authed="1"
      data-hasfiles="0"
      data-isarraybody="0"
      data-headers='{"Authorization":"Bearer {YOUR_AUTH_KEY}","Content-Type":"application\/json","Accept":"application\/json"}'
      autocomplete="off"
      onsubmit="event.preventDefault(); executeTryOut('POSTapi-auth-logout', this);">
    <h3>
        Request&nbsp;&nbsp;&nbsp;
            </h3>
            <p>
            <small class="badge badge-black">POST</small>
            <b><code>api/auth/logout</code></b>
        </p>
                <p>
            <label id="auth-POSTapi-auth-logout" hidden>Authorization header:
                <b><code>Bearer </code></b><input type="text"
                                                                name="Authorization"
                                                                data-prefix="Bearer "
                                                                data-endpoint="POSTapi-auth-logout"
                                                                data-component="header"></label>
        </p>
                </form>

    

        
    </div>
    <div class="dark-box">
                    <div class="lang-selector">
                                                        <button type="button" class="lang-button" data-language-name="javascript">javascript</button>
                                                        <button type="button" class="lang-button" data-language-name="bash">bash</button>
                                                        <button type="button" class="lang-button" data-language-name="php">php</button>
                                                        <button type="button" class="lang-button" data-language-name="python">python</button>
                            </div>
            </div>
</div>
</body>
</html>
