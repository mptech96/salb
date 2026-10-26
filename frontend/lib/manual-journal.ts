export function manualJournalAccess(session:any):{post:boolean;reverse:boolean}{
 const user=session?.user,permissions=session?.permissions||user?.permissions||[];
 if(user?.is_support_mode){const writable=user.support_access_level==="WRITE",caps=user.support_capabilities||[];return {post:writable&&caps.includes("POST:api/journal-entries"),reverse:writable&&caps.includes("POST:api/journal-entries/{id}/reverse")};}
 const manager=["MANAGER","COMPANY_MANAGER","COMPANY_ADMIN","COMPANY_OWNER","ADMIN"].includes(String(user?.role?.role_code||"").toUpperCase());
 return {post:manager||permissions.includes("journals.post"),reverse:manager||permissions.includes("journals.reverse")};
}
