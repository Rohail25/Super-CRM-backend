<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Project</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; }
        .container { width: 100%; height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f5f5; }
        .loading { text-align: center; }
        .spinner { border: 4px solid #e0e0e0; border-top: 4px solid #2563eb; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 16px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .message { color: #666; font-size: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="loading">
            <div class="spinner"></div>
            <p class="message">Loading doctor project...</p>
        </div>
    </div>

    <script>
        // Pass data to parent window so frontend can display it
        // The frontend already fetched this data via the SSO endpoint
        // This proxy is just a placeholder/fallback
        console.log('Doctor proxy page loaded for user: {{ $user->email }}');
        
        // Notify parent that we're ready (if in iframe)
        if (window.parent !== window) {
            window.parent.postMessage({
                type: 'doctor-proxy-ready',
                user: {
                    id: {{ $user->id }},
                    email: '{{ $user->email }}',
                    name: '{{ $user->name }}'
                }
            }, '*');
        }
    </script>
</body>
</html>
