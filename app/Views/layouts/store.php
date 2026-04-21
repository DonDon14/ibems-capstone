<!DOCTYPE html>
<html>
<head>
    <title>Store System</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        header {
            background: #222;
            color: white;
            padding: 15px 20px;
        }

        nav {
            background: #f4f4f4;
            padding: 10px 20px;
        }

        nav a {
            margin-right: 15px;
            text-decoration: none;
            color: #333;
            font-weight: bold;
        }

        .container {
            padding: 20px;
        }
    </style>
</head>
<body>

<header>
    <h2>IBEMS - Store System</h2>
</header>

<nav>
    <a href="/store/pos">POS</a>
    <a href="/auth/logout">Logout</a>
</nav>

<div class="container">
    <?= $this->renderSection('content') ?>
</div>

</body>
</html>