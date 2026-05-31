<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Faruza Shop API</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #1e293b; }
        main { text-align: center; padding: 2rem; }
        h1 { font-size: 1.75rem; margin-bottom: 0.5rem; }
        p { color: #64748b; margin: 0.25rem 0; }
        ul { list-style: none; padding: 0; margin: 1rem 0 0; }
        li { margin: 0.5rem 0; }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .note { font-size: 0.9rem; margin-top: 1rem; max-width: 28rem; }
    </style>
</head>
<body>
    <main>
        <h1>Faruza Shop API</h1>
        <p>Backend is running. All API routes use the <code>/api</code> prefix.</p>
        <ul>
            <li><a href="/api/products">GET /api/products</a> — list products</li>
            <li><code>POST /api/products</code> — create product (use Postman)</li>
        </ul>
        <p class="note"><strong>Note:</strong> <code>/products</code> alone returns 404. Use <code>/api/products</code>.</p>
    </main>
</body>
</html>
