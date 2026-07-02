import pkg from "electron";
const { app, BrowserWindow } = pkg;
import path from "path";
import { fileURLToPath } from "url";
import { exec } from "child_process";
import { promisify } from "util";

const execAsync = promisify(exec);

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function createWindow() {
  const win = new BrowserWindow({
    width: 1200,
    height: 800,
    autoHideMenuBar: true,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      preload: path.join(__dirname, "preload.cjs"),
    },
    icon: path.join(__dirname, "../dist/icon.png"),
  });
  
  win.setTitle("PCTG PC Builder");

  // In production, load the index.html from the dist folder
  // In development, you might want to load from localhost:5173
  const isDev = process.env.NODE_ENV === "development";
  
  if (isDev) {
    win.loadURL("http://localhost:5173");
  } else {
    win.loadFile(path.join(__dirname, "../dist/index.html"));
  }

}

app.whenReady().then(() => {
  // Trigger auto-update check
  const autoUpdateScript = path.join(__dirname, "../scripts/auto-update.js");
  exec(`node "${autoUpdateScript}"`, (err, stdout, stderr) => {
    if (err) console.error("Auto-update error:", err);
    if (stdout) console.log("Auto-update stdout:", stdout);
    if (stderr) console.error("Auto-update stderr:", stderr);
  });

  pkg.ipcMain.handle('pypp:search', async (event, query) => {
    try {
      const pythonPath = "python"; // Or path to python executable
      const scriptPath = path.join(__dirname, "../pypp_bridge.py");
      const { stdout, stderr } = await execAsync(`"${pythonPath}" "${scriptPath}" "${query.replace(/"/g, '\\"')}"`);
      
      if (stderr) {
        console.error("Python stderr:", stderr);
      }
      
      return JSON.parse(stdout);
    } catch (error) {
      console.error("Search error:", error);
      return { error: error.message };
    }
  });

  createWindow();

  app.on("activate", () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow();
    }
  });
});

app.on("window-all-closed", () => {
  if (process.platform !== "darwin") {
    app.quit();
  }
});
