const { app, BrowserWindow } = require('electron');
const express = require('express');
const path = require('path');

let floatingIcon = null;
let chatWindow = null;
let phpServer = null;

// Start Express server to serve PHP files
function startServer() {
    const serverApp = express();
    const port = 8000;

    // Serve static files
    serverApp.use(express.static(__dirname));

    // Handle POST requests to jimmini-api.php
    serverApp.post('/jimmini-api.php', express.json(), async (req, res) => {
        const { exec } = require('child_process');
        const tempFile = path.join(__dirname, 'temp-request.json');
        const fs = require('fs');
        
        // Write request to temp file
        fs.writeFileSync(tempFile, JSON.stringify(req.body));
        
        // Execute PHP script
        exec(`php jimmini-api-electron.php "${tempFile}"`, (error, stdout, stderr) => {
            if (error) {
                res.json({ success: false, error: stderr || error.message });
                return;
            }
            
            try {
                const result = JSON.parse(stdout);
                res.json(result);
            } catch (e) {
                res.json({ success: false, error: 'Failed to parse PHP response' });
            }
            
            // Clean up temp file
            if (fs.existsSync(tempFile)) {
                fs.unlinkSync(tempFile);
            }
        });
    });

    phpServer = serverApp.listen(port, () => {
        console.log(`Server running on http://localhost:${port}`);
    });
}

function createFloatingIcon() {
    const { screen } = require('electron');
    const primaryDisplay = screen.getPrimaryDisplay();
    const { width, height } = primaryDisplay.workAreaSize;

    // POSITION SETTINGS - Adjust these values to change icon position
    const iconRightOffset = 200;  // Distance from right edge (decrease to move right, increase to move left)
    const iconBottomOffset = 180; // Distance from bottom edge (decrease to move down, increase to move up)

    floatingIcon = new BrowserWindow({
        width: 150,
        height: 150,
        x: width - iconRightOffset,   // Horizontal position
        y: height - iconBottomOffset, // Vertical position
        transparent: true,
        frame: false,
        alwaysOnTop: true,
        skipTaskbar: true,
        resizable: false,
        webPreferences: {
            nodeIntegration: true,
            contextIsolation: false
        }
    });

    // Load the HTML file from disk
    const iconHTMLPath = path.join(__dirname, 'floating-icon.html');
    floatingIcon.loadFile(iconHTMLPath);
    
    floatingIcon.setIgnoreMouseEvents(false);
}

function createChatWindow() {
    // If chat window exists and is visible, close it (toggle off)
    if (chatWindow && chatWindow.isVisible()) {
        chatWindow.close();
        chatWindow = null;
        return;
    }

    // If chat window exists but is hidden, show it
    if (chatWindow) {
        if (chatWindow.isMinimized()) chatWindow.restore();
        chatWindow.show();
        chatWindow.focus();
        return;
    }

    const { screen } = require('electron');
    const primaryDisplay = screen.getPrimaryDisplay();
    const { width, height } = primaryDisplay.workAreaSize;

    chatWindow = new BrowserWindow({
        width: 450,
        height: 700,
        x: width - 460, // Position from right edge
        y: 20, // Position 20px from top
        frame: true,
        resizable: true,
        alwaysOnTop: true,
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true
        },
        icon: path.join(__dirname, 'ifen_logo_masc_2.png')
    });

    chatWindow.loadURL('http://localhost:8000/jimmini-chat.html');

    // Handle window close
    chatWindow.on('close', () => {
        chatWindow = null;
    });
}

// Start server when app is ready
app.whenReady().then(() => {
    // Clear conversation history from previous session
    const historyFile = path.join(__dirname, 'conversation_history.txt');
    const fs = require('fs');
    if (fs.existsSync(historyFile)) {
        try {
            fs.unlinkSync(historyFile);
            console.log('Previous conversation history cleared');
        } catch (err) {
            console.error('Failed to clear conversation history:', err);
        }
    }
    
    startServer();
    createFloatingIcon();

    // Listen for open-chat event from floating icon
    const { ipcMain } = require('electron');
    ipcMain.on('open-chat', () => {
        createChatWindow();
    });

    app.on('activate', () => {
        if (!floatingIcon) {
            createFloatingIcon();
        }
    });
});

// Quit when floating icon is closed
app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') {
        app.quit();
    }
});

// Cleanup on quit
app.on('before-quit', () => {
    if (phpServer) {
        phpServer.close();
    }
});
