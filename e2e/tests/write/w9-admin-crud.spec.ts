import { test, expect, resetFor } from '../support/write-fixtures';
import { diffTable, diffText, rows, tableRows } from '../support/effects';
import { AdminCrudPage, CalendarAdminPage } from '../pages/admin.page';

/**
 * W9 — admin CRUD round trips (Crud scaffold controllers + CalendarAdmin).
 * Each round trip adds a row, renames it, then deletes it; the after-delete
 * diff golden pins that the round trip leaves the table exactly as found.
 * "Before" snapshots are taken after login because the first admin login
 * creates that user's contacts row (user2 has no fixture contact).
 */
test.beforeEach(async () => {
  await resetFor();
});

test('categories round trip: add, rename, delete', async ({ session }) => {
  const page = await session('admin');
  const crud = new AdminCrudPage(page);
  const before = await tableRows('categories');
  await crud.gotoAdd('categories');
  await crud.fillField('Name', 'Write Test Category');
  await crud.submitAdd('Category');
  await crud.gotoIndex('categories');
  await expect(crud.rowFor('Write Test Category')).toBeVisible();
  const added = await rows('SELECT * FROM `categories` WHERE `name` = ?', ['Write Test Category']);
  expect(Object.keys(added)).toHaveLength(1);
  const id = Number(Object.keys(added)[0]);
  const afterAdd = await tableRows('categories');
  expect(diffText({ categories: diffTable(before, afterAdd) })).toMatchSnapshot('w9-categories-add.txt');

  await crud.gotoEdit('categories', id);
  await crud.fillField('Name', 'Write Test Category Renamed');
  await crud.submitEdit('Category');
  await crud.gotoIndex('categories');
  await expect(crud.rowFor('Write Test Category Renamed')).toBeVisible();
  const afterEdit = await tableRows('categories');
  expect(diffText({ categories: diffTable(before, afterEdit) })).toMatchSnapshot('w9-categories-edit.txt');

  await crud.deleteRow('Write Test Category Renamed');
  await expect(crud.rowFor('Write Test Category Renamed')).toHaveCount(0);
  const afterDelete = await tableRows('categories');
  expect(diffText({ categories: diffTable(before, afterDelete) })).toMatchSnapshot('w9-categories-delete.txt');
});

test('tools round trip: add, rename, delete', async ({ session }) => {
  const page = await session('admin');
  const crud = new AdminCrudPage(page);
  const before = await tableRows('tools');
  await crud.gotoAdd('tools');
  await crud.fillField('Name', 'Write Test Tool');
  await crud.submitAdd('Tool');
  await crud.gotoIndex('tools');
  await expect(crud.rowFor('Write Test Tool')).toBeVisible();
  const added = await rows('SELECT * FROM `tools` WHERE `name` = ?', ['Write Test Tool']);
  expect(Object.keys(added)).toHaveLength(1);
  const id = Number(Object.keys(added)[0]);
  const afterAdd = await tableRows('tools');
  expect(diffText({ tools: diffTable(before, afterAdd) })).toMatchSnapshot('w9-tools-add.txt');

  await crud.gotoEdit('tools', id);
  await crud.fillField('Name', 'Write Test Tool Renamed');
  await crud.submitEdit('Tool');
  await crud.gotoIndex('tools');
  await expect(crud.rowFor('Write Test Tool Renamed')).toBeVisible();
  const afterEdit = await tableRows('tools');
  expect(diffText({ tools: diffTable(before, afterEdit) })).toMatchSnapshot('w9-tools-edit.txt');

  await crud.deleteRow('Write Test Tool Renamed');
  await expect(crud.rowFor('Write Test Tool Renamed')).toHaveCount(0);
  const afterDelete = await tableRows('tools');
  expect(diffText({ tools: diffTable(before, afterDelete) })).toMatchSnapshot('w9-tools-delete.txt');
});

test('contacts round trip: add, rename, delete', async ({ session }) => {
  const page = await session('admin');
  const crud = new AdminCrudPage(page);
  const before = await tableRows('contacts');
  await crud.gotoAdd('contacts');
  await crud.fillField('Name', 'Write Test Contact');
  await crud.fillField('Email', 'write.contact@example.test');
  await crud.fillField('Phone', '555-0199');
  await crud.submitAdd('Contact');
  await crud.gotoIndex('contacts');
  await expect(crud.rowFor('Write Test Contact')).toBeVisible();
  const added = await rows('SELECT * FROM `contacts` WHERE `name` = ?', ['Write Test Contact']);
  expect(Object.keys(added)).toHaveLength(1);
  const id = Number(Object.keys(added)[0]);
  const afterAdd = await tableRows('contacts');
  expect(diffText({ contacts: diffTable(before, afterAdd) })).toMatchSnapshot('w9-contacts-add.txt');

  await crud.gotoEdit('contacts', id);
  await crud.fillField('Name', 'Write Test Contact Renamed');
  await crud.submitEdit('Contact');
  await crud.gotoIndex('contacts');
  await expect(crud.rowFor('Write Test Contact Renamed')).toBeVisible();
  const afterEdit = await tableRows('contacts');
  expect(diffText({ contacts: diffTable(before, afterEdit) })).toMatchSnapshot('w9-contacts-edit.txt');

  await crud.deleteRow('Write Test Contact Renamed');
  await expect(crud.rowFor('Write Test Contact Renamed')).toHaveCount(0);
  const afterDelete = await tableRows('contacts');
  expect(diffText({ contacts: diffTable(before, afterDelete) })).toMatchSnapshot('w9-contacts-delete.txt');
});

test('super admin edits the calendar admin message', async ({ session }) => {
  const page = await session('superAdmin');
  const crud = new CalendarAdminPage(page);
  const beforeSuper = await tableRows('calendar_super_configurations');
  const beforeConfig = await tableRows('configurations');
  await crud.goto();
  await expect(crud.heading).toBeVisible();
  await crud.messageInput.fill('Write test honoraria message');
  await crud.save();
  const afterSuper = await tableRows('calendar_super_configurations');
  const afterConfig = await tableRows('configurations');
  const diff = diffTable(beforeSuper, afterSuper);
  expect(diff.changed['1'].value).toEqual(['', 'Write test honoraria message']);
  expect(diffText({ calendar_super_configurations: diff, configurations: diffTable(beforeConfig, afterConfig) })).toMatchSnapshot('w9-calendar-admin.txt');
  await crud.goto();
  await expect(crud.messageInput).toHaveValue('Write test honoraria message');
});
