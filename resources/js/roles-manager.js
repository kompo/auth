function changeLinkGroupColor(optionClass)
{
    let current = $("." + optionClass + ".perm-selected").eq(0)
    let next = current.parent().next().find("." + optionClass).eq(0)
    if (!next.length) {
        next = $("." + optionClass).eq(0)
    }

    $("." + optionClass).addClass("hidden")
    current.removeClass("perm-selected")
    next.removeClass("hidden").addClass("perm-selected")
}

function changeLinkGroupColorToIndex(optionClass, index)
{
    let current = $("." + optionClass + ".perm-selected").eq(0)
    let next = $("." + optionClass).eq(index)

    $("." + optionClass).addClass("hidden")
    current.removeClass("perm-selected")
    next.removeClass("hidden").addClass("perm-selected")
}

function changeNullOptionColorToIndex(parentCheckbox, indexes) 
{
    $("." + parentCheckbox + " .subsection-item").addClass("hidden");

    indexes.forEach(index => {
        $("." + parentCheckbox + " .subsection-item").eq(index).removeClass("hidden")
    })
}

function checkMultipleLinkGroupColor(parentCheckbox, role, permissionsIds, separator = ",")
{
    let indexes = new Set();

    for (permissionId of permissionsIds.split(separator)) {
        let selected = $("." + role + '-' + permissionId + ".perm-selected").eq(0)
        let index = $("." + role + '-' + permissionId).index(selected)

        indexes.add(index)
    }

    if (indexes.size > 1) {
        changeNullOptionColorToIndex(parentCheckbox, indexes)
        return changeLinkGroupColorToIndex(parentCheckbox, 0)
    }

    changeLinkGroupColorToIndex(parentCheckbox, [...indexes][0])
}

function changeMultipleLinkGroupColor(parentCheckbox, role, permissionsIds, separator = ",")
{
    let selected = $("." + parentCheckbox + ".perm-selected").eq(0)
    let index = $("." + parentCheckbox).index(selected)

    let permissions = permissionsIds.split(separator)

    permissions.forEach(permissionId => {
        changeLinkGroupColorToIndex(role + "-" + permissionId, index)
    })
}

function cleanLinkGroupNullOption(name)
{
    $("." + name + " .subsection-item").addClass("hidden");
    $("." + name + " .subsection-item").eq(0).addClass("hidden");
}

function getSelectedRoles()
{
    const multiselect = $("input[name=roles]");
    const selectOptions = multiselect.parent().find(".vlTags").find("div[data-role-id]");
    const roles = [...selectOptions].map((o) => ({
        roleId: $(o).data("roleId"),
        roleName: $(o).text()
    }));

    return roles;
}

function getHeaderTemplate(roleId, roleName)
{
    return $("<div>").attr("data-role-header-example", roleId).addClass("roles-manager-role-header").text(roleName);
}

function getContentTemplate(roleId)
{
    return $("<div>").attr("data-role-id", roleId).text("").attr("data-void", "true");
}

function precreateRoleVisuals() {
    const roles = getSelectedRoles();

    // Injecting template divs for selected roles
    roles.forEach(({roleId, roleName}) => {
        const isAlreadyCreated = $("#roles-manager-matrix .roles-manager-rows").find(`div[data-role-id=${roleId}]`).length;

        if (isAlreadyCreated) {
            return;
        }

        $("#roles-manager-matrix .roles-manager-rows").each((i, e) => {
            const isHeader = $(e).parent().attr("id") == "roles-header";

            if (isHeader) {
                $(e).append(getHeaderTemplate(roleId, roleName));
            } else { 
                $(e).append(getContentTemplate(roleId));
            }
        });
    });

    // Iterating over each role to hide the column or show it
    $("#roles-manager-matrix .roles-manager-rows").find("div[data-role-id]").each((i, e) => {
        if(rolesIds.includes($(e).data("roleId"))) {
            $(e).show();
        } else {
            $(e).hide();
        }
    });
}

function injectRoleContent() {
    setTimeout(() => {
        // Inject role headers
        $("#roles-header").find("div[data-void]").each((i, e) => {
            const roleId = $(e).attr("data-role-id");
            const roleName = $(e).attr("data-role-name");

            if(!roleId) {
                return;
            }

            $(e).text(roleName);
            $(e).removeAttr("data-void");

            e.parentNode.replaceChild(
                $("div[data-role-header-example=\"" + roleId + "\"]").get(0), e
            );
        });

        // Inject role content
        $("#roles-manager-matrix .roles-manager-rows div[data-void=\"true\"]").each((i, e) => {
            const permissionId = $(e).parent().data("permission-id");
            const roleId = $(e).data("role-id");
            const permissionSectionId = $(e).parent().data("permission-section-id");

            const visual = e;
            if(!roleId || (!permissionId && !permissionSectionId)) {
                return;
            }

            $("div[data-role-template=\"" + roleId + "-" + permissionId + "\"]").each((i, e) => {
                visual.parentNode.replaceChild(e, visual);
            });

            $("div[data-permission-section-template=\"" + roleId + "-" + permissionSectionId + "\"]").each((i, e) => {
                visual.parentNode.replaceChild(e, visual);
            });
        });
    }, 1000);
}

function searchLoadingOn(id) {
    $("#" + id).removeClass("hidden");
}

function searchLoadingOff(id) {
    $("#" + id).addClass("hidden");
}