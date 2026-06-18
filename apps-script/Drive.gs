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

function getOrCreateSubFolder(parent, name) {
  const existing = parent.getFoldersByName(name);
  return existing.hasNext() ? existing.next() : parent.createFolder(name);
}

function createProjectFolder(projectName, tool) {
  const base = getOrCreateBaseFolder();
  const toolName = tool ? tool.charAt(0).toUpperCase() + tool.slice(1) : 'Genel';
  const toolFolder = getOrCreateSubFolder(base, toolName);
  const projectFolder = getOrCreateSubFolder(toolFolder, projectName);
  ['Arastirma', 'Taslaklar', 'Final', 'API_Ciktilari'].forEach(sub => {
    getOrCreateSubFolder(projectFolder, sub);
  });
  return projectFolder.getUrl();
}

function saveToDrive(data) {
  if (!data.project) return '';
  try {
    const base = getOrCreateBaseFolder();
    const toolName = data.tool ? data.tool.charAt(0).toUpperCase() + data.tool.slice(1) : 'Genel';
    const toolFolder = getOrCreateSubFolder(base, toolName);
    const projectFolder = getOrCreateSubFolder(toolFolder, data.project);
    const apiFolder = getOrCreateSubFolder(projectFolder, 'API_Ciktilari');
    const date = Utilities.formatDate(new Date(), 'Europe/Istanbul', 'yyyy-MM-dd_HH-mm');
    const fileName = date + '-' + (data.tool || 'ai') + '-konusma.md';
    const content = [
      '# ' + data.project + ' — ' + (data.tool || 'AI') + ' Konusmasi',
      '',
      '**Tarih:** ' + new Date().toLocaleString('tr-TR'),
      '**Cihaz:** ' + (data.device || 'Bilinmiyor'),
      '**Arac:** ' + (data.tool || 'Bilinmiyor'),
      '**Token:** ' + (data.tokens_used || 0),
      '',
      '## Prompt',
      '',
      data.prompt_summary || '',
      '',
      '## Yanit',
      '',
      data.response_summary || '',
      '',
      '## Tam Icerik',
      '',
      data.full_content || ''
    ].join('\n');
    const file = apiFolder.createFile(fileName, content, MimeType.PLAIN_TEXT);
    return file.getUrl();
  } catch (e) {
    Logger.log('Drive save error: ' + e.toString());
    return '';
  }
}
