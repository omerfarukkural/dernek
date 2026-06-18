const BASE_FOLDER_NAME = 'AI-Projeler';

function getOrCreateBaseFolder() {
  const props = PropertiesService.getScriptProperties();
  let folderId = props.getProperty('BASE_FOLDER_ID');
  if (folderId) {
    try { return DriveApp.getFolderById(folderId); } catch (e) {}
  }
  const folders = DriveApp.getFoldersByName(BASE_FOLDER_NAME);
  const folder = folders.hasNext() ? folders.next() : DriveApp.createFolder(BASE_FOLDER_NAME);
  props.setProperty('BASE_FOLDER_ID', folder.getId());
  return folder;
}

function createProjectFolder(projectName, tool) {
  const base = getOrCreateBaseFolder();
  const toolName = (tool || 'Genel').charAt(0).toUpperCase() + (tool || 'Genel').slice(1);
  let toolFolder;
  const toolFolders = base.getFoldersByName(toolName);
  toolFolder = toolFolders.hasNext() ? toolFolders.next() : base.createFolder(toolName);
  let projectFolder;
  const pFolders = toolFolder.getFoldersByName(projectName);
  projectFolder = pFolders.hasNext() ? pFolders.next() : toolFolder.createFolder(projectName);
  ['Araştırma', 'Taslaklar', 'Final', 'API_Ciktilari'].forEach(sub => {
    const subs = projectFolder.getFoldersByName(sub);
    if (!subs.hasNext()) projectFolder.createFolder(sub);
  });
  return projectFolder.getUrl();
}

function saveToDrive(data) {
  if (!data.project) return '';
  try {
    const base = getOrCreateBaseFolder();
    const toolName = (data.tool || 'Genel').charAt(0).toUpperCase() + (data.tool || 'Genel').slice(1);
    let toolFolder;
    const tf = base.getFoldersByName(toolName);
    toolFolder = tf.hasNext() ? tf.next() : base.createFolder(toolName);
    let projectFolder;
    const pf = toolFolder.getFoldersByName(data.project);
    projectFolder = pf.hasNext() ? pf.next() : toolFolder.createFolder(data.project);
    let apiFolder;
    const af = projectFolder.getFoldersByName('API_Ciktilari');
    apiFolder = af.hasNext() ? af.next() : projectFolder.createFolder('API_Ciktilari');
    const date = Utilities.formatDate(new Date(), 'Europe/Istanbul', 'yyyy-MM-dd_HH-mm');
    const fileName = date + '-' + (data.tool || 'ai') + '-konusma.md';
    const content = '# ' + data.project + ' — ' + data.tool + ' Konusması\n\n' +
      '**Tarih:** ' + new Date().toLocaleString('tr-TR') + '\n' +
      '**Cihaz:** ' + (data.device || 'Bilinmiyor') + '\n' +
      '**Arac:** ' + (data.tool || 'Bilinmiyor') + '\n' +
      '**Token:** ' + (data.tokens_used || 0) + '\n\n' +
      '## Prompt\n\n' + (data.prompt_summary || '') + '\n\n' +
      '## Yanit\n\n' + (data.response_summary || '') + '\n\n' +
      '## Tam Icerik\n\n' + (data.full_content || '');
    const file = apiFolder.createFile(fileName, content, MimeType.PLAIN_TEXT);
    return file.getUrl();
  } catch (e) {
    Logger.log('Drive save error: ' + e.toString());
    return '';
  }
}
