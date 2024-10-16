<!DOCTYPE html>
<head>
  <title>Pusher Test</title>
  <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
<body>
  <h1>Pusher Test</h1>
  <p>
    Publish an event to channel <code>my-channel</code>
    with event name <code>my-event</code>; it will appear below:
  </p>
  <div id="app">
    <ul>
      <li v-for="message in messages">
        {{message}}
      </li>
    </ul>
  </div>

  <script src="https://js.pusher.com/8.2.0/pusher.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vue/dist/vue.js"></script>
  <script>
    // Enable pusher logging - don't include this in production
    Pusher.logToConsole = true;

    var pusher = new Pusher('e0774a8dfe10c45934e8', {
      cluster: 'eu'
    });

    var channel = pusher.subscribe('my-channel');
    channel.bind('my-event', function(data) {
      app.messages.push(JSON.stringify(data));
    });

    // Vue application
    const app = new Vue({
      el: '#app',
      data: {
        messages: [],
      },
    });
  </script>
</body>