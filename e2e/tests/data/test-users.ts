/** OpenLDAP seed users from dms-ad-openldap/03-users.ldif */
export const testUsers = {
  member: { username: 'user1', password: 'password' },
  admin: { username: 'user2', password: 'password' },
} as const;
