const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('pypartpicker', {
    search: (query) => ipcRenderer.invoke('pypp:search', query)
});
